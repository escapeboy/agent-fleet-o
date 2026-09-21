#!/usr/bin/env python3
"""Build the next-tool-prediction eval dataset from Phoenix traces.

One case per tool call the agent actually made, inside a session that belongs to
an experiment that reached `completed`. This measures next-tool prediction inside
a coding loop — it is NOT FleetQ domain routing; that is routing-v2.jsonl. The state holds the task brief and the
steps already taken; the gold answer is the tool that was in fact chosen next.

The assistant's own narration is deliberately left out of the state. It is
"available at decision time", but it routinely announces the next tool by name,
which would turn a routing judgment into string extraction and make every
accuracy number meaningless.

Usage
-----
  # pull fresh dumps from production, then build
  python3 export_routing_dataset.py --host katsarov@195.201.195.167

  # rebuild from dumps already on disk (no ssh)
  python3 export_routing_dataset.py --cache-dir /tmp/jev-export --from-cache

Output: ~/jev-eval/datasets/fleetq/next-tool.jsonl
"""

from __future__ import annotations

import argparse
import collections
import hashlib
import json
import os
import pathlib
import random
import re
import subprocess
import sys

# --- taxonomy ---------------------------------------------------------------
# Top-level domains, and the tools that belong to each. A tool seen in the
# traces but missing here lands in `unmapped` and its cases are dropped rather
# than silently folded into "other" — a mislabelled gold answer is worse than a
# smaller dataset.

DOMAINS: dict[str, dict[str, str]] = {
    "filesystem": {
        "Read": "Read the contents of a file at a known path",
        "Edit": "Change part of an existing file in place",
        "Write": "Create a file, or replace one entirely",
    },
    "shell": {
        "Bash": "Run a shell command and read its output",
        "Monitor": "Watch an already-running command or condition until it changes",
    },
    "delegation": {
        "Agent": "Hand a self-contained piece of work to another agent",
        "SendMessage": "Send a message to an agent that is already running",
        "ListAgents": "List the agents that can be messaged",
    },
    "task_tracking": {
        "TaskCreate": "Record a new unit of work on the task list",
        "TaskUpdate": "Change the state or detail of a task already on the list",
        "TaskGet": "Read one task back",
        "TaskList": "Read the whole task list back",
        "TaskStop": "Stop a task that is running",
        "TaskOutput": "Read the output a task produced",
    },
    "web": {
        "WebFetch": "Fetch the contents of a specific URL",
        "WebSearch": "Search the web for pages about a topic",
    },
    "scheduling": {
        "ScheduleWakeup": "Arrange to continue this work later",
        "CronList": "Read the list of scheduled jobs",
        "CronDelete": "Remove a scheduled job",
        "RemoteTrigger": "Trigger a job on another machine",
    },
    "platform": {
        "experiment_complete_building": "Tell the FleetQ platform this experiment's build stage is finished",
    },
}

DOMAIN_DESCRIPTIONS = {
    "filesystem": "Look at or change a file whose path is already known",
    "shell": "Run or watch a command on the machine",
    "delegation": "Give work to another agent, or talk to one",
    "task_tracking": "Keep the task list up to date",
    "web": "Get something from the internet",
    "scheduling": "Arrange work to happen later or elsewhere",
    "platform": "Report progress back to the FleetQ platform",
    "other": "Something none of the options above describes",
}

TOOL_TO_DOMAIN = {tool: domain for domain, tools in DOMAINS.items() for tool in tools}

# Per-domain cap. The traces are dominated by Read and Bash; without a cap the
# dataset would measure one decision repeated 1,000 times.
DOMAIN_CAPS = {"filesystem": 200, "shell": 200}

MAX_STEPS_IN_STATE = 12
MAX_DETAIL_CHARS = 160
MAX_BRIEF_CHARS = 1200
SAMPLE_SEED = 20260920

# --- scrubbing --------------------------------------------------------------

UUID_RE = re.compile(r"\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b", re.I)
EMAIL_RE = re.compile(r"\b[\w.+-]+@[\w-]+\.[\w.-]+\b")
URL_CREDS_RE = re.compile(r"\b[a-z][a-z0-9+.-]*://[^\s/@]+:[^\s/@]+@", re.I)
TOKEN_RE = re.compile(
    r"\b("
    r"sk-[A-Za-z0-9_\-]{12,}"
    r"|gh[pousr]_[A-Za-z0-9]{16,}"
    r"|glpat-[A-Za-z0-9_\-]{16,}"
    r"|AKIA[0-9A-Z]{12,}"
    r"|eyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}"
    r")\b"
)
SECRET_ASSIGN_RE = re.compile(
    r"(?i)\b(api[_-]?key|apikey|secret|token|password|passwd|pwd|authorization|bearer)\b\s*[:=]\s*\S+"
)
LONG_HEX_RE = re.compile(r"\b[0-9a-f]{32,}\b", re.I)
# Tenant workspace prefixes. Runs AFTER the uuid and hash substitutions, so by
# this point a checkout path looks like
#   /var/www/storage/app/warm-repos/<id>/<id>.worktrees/<id>-c1/app/Foo.php
# and what is left after stripping is the repo-relative file the agent actually
# reasons about.
WORKSPACE_PREFIX_RE = re.compile(
    r"(?:/var/www/storage/app/warm-repos|/tmp/claude-vps|/var/www/html|/opt/agent-fleet|/var/www)"
    r"(?:/(?:<id>|<hash>)(?:\.worktrees)?(?:-c\d+)?)*/?"
)
RESIDUE_RE = re.compile(
    r"(?i)(@[\w-]+\.[a-z]{2,}|\bsk-[A-Za-z0-9]{12,}|\bgh[pousr]_|\bglpat-|\bAKIA|\b[0-9a-f]{32,}\b)"
)


def scrub(text: str) -> str:
    text = URL_CREDS_RE.sub("<redacted-url>://", text)
    text = EMAIL_RE.sub("<email>", text)
    text = TOKEN_RE.sub("<token>", text)
    text = SECRET_ASSIGN_RE.sub(r"\1=<redacted>", text)
    text = UUID_RE.sub("<id>", text)
    text = LONG_HEX_RE.sub("<hash>", text)
    text = WORKSPACE_PREFIX_RE.sub("", text)
    return text.strip()


def is_clean(text: str) -> bool:
    """A scrubbed string still carrying a secret-shaped substring drops its case.

    The brief is explicit: when scrubbing cannot be guaranteed, drop the trace.
    """
    return RESIDUE_RE.search(text) is None


# --- fetching ---------------------------------------------------------------

EXPERIMENTS_SQL = """
select coalesce(json_agg(row_to_json(t)), '[]'::json)::text from (
  select id, title, thesis from experiments where status = 'completed'
) t;
"""

SPANS_SQL_TEMPLATE = """
with sess as (
  select attributes->'metadata'->>'session_id' as sid,
         attributes->'metadata'->>'experiment_id' as exp_id
  from spans
  where name = 'local_agent.session'
    and attributes->'metadata'->>'experiment_id' in ({ids})
)
select coalesce(json_agg(row_to_json(t) order by t.sid, t.start_time, t.id), '[]'::json)::text from (
  select sess.sid, sess.exp_id, s.id, s.name, s.start_time,
         (s.attributes->'metadata'->>'turn_index')::int as turn_index,
         s.attributes->'tool'->>'name' as tool_name,
         s.attributes->'tool'->'parameters' as tool_params
  from spans s
  join sess on sess.sid = s.attributes->'metadata'->>'session_id'
  where s.name like 'local_agent.tool.%'
) t;
"""


def ssh_json(host: str, script: str) -> list[dict]:
    result = subprocess.run(
        ["ssh", "-o", "ConnectTimeout=30", "-o", "BatchMode=yes", host, script],
        capture_output=True,
        text=True,
        check=True,
    )
    start = result.stdout.index("[")
    return json.loads(result.stdout[start:])


def fetch(host: str, cache_dir: pathlib.Path) -> tuple[list[dict], list[dict]]:
    cache_dir.mkdir(parents=True, exist_ok=True)

    experiments = ssh_json(
        host,
        f'docker exec agent-fleet-postgres psql -U agent_fleet -d agent_fleet -P pager=off -At -c "{EXPERIMENTS_SQL}"',
    )
    (cache_dir / "experiments.json").write_text(json.dumps(experiments))

    ids = ",".join(f"'{e['id']}'" for e in experiments)
    spans = ssh_json(
        host,
        f'docker exec agent-fleet-phoenix-postgres psql -U phoenix -d phoenix -P pager=off -At -c "{SPANS_SQL_TEMPLATE.format(ids=ids)}"',
    )
    (cache_dir / "spans.json").write_text(json.dumps(spans))

    return experiments, spans


def load_cache(cache_dir: pathlib.Path) -> tuple[list[dict], list[dict]]:
    def read(name: str) -> list[dict]:
        text = (cache_dir / name).read_text()
        return json.loads(text[text.index("[") :])

    return read("experiments.json"), read("spans.json")


# --- case building ----------------------------------------------------------


def detail_for(tool: str, params: dict | None) -> str:
    params = params or {}

    if tool in ("Read", "Edit", "Write"):
        raw = str(params.get("file_path") or params.get("path") or "")
    elif tool == "Bash":
        raw = str(params.get("command") or "")
    elif tool == "Agent":
        raw = str(params.get("description") or params.get("prompt") or "")
    elif tool in ("WebFetch",):
        raw = str(params.get("url") or params.get("prompt") or "")
    elif tool == "WebSearch":
        raw = str(params.get("query") or "")
    elif tool.startswith("Task"):
        raw = str(params.get("description") or params.get("prompt") or params.get("task_id") or "")
    else:
        raw = json.dumps(params, ensure_ascii=False)

    return scrub(raw)[:MAX_DETAIL_CHARS]


def split_for(case_id: str) -> str:
    """Mirrors App\\Domain\\Decision\\Services\\SplitAssigner exactly."""
    bucket = int(hashlib.sha256(case_id.encode()).hexdigest()[:8], 16) % 100
    return "dev" if bucket < 20 else "test"


def questions_for(gold_domain: str) -> dict:
    tools = DOMAINS[gold_domain]

    domain_criteria = {name: DOMAIN_DESCRIPTIONS[name] for name in DOMAINS}
    domain_criteria["other"] = DOMAIN_DESCRIPTIONS["other"]

    tool_criteria = dict(tools)
    tool_criteria["other"] = "A different tool from the one the options above describe"

    return {
        "domain": {
            "type": "choice",
            "instructions": (
                "The agent is working through `task` and has already taken the steps in "
                "`steps_so_far`. Which kind of tool does it reach for at step `step_index`?"
            ),
            "criteria": domain_criteria,
        },
        "tool": {
            "type": "choice",
            "instructions": (
                f"The agent's next step uses a {gold_domain} tool. Which one does it use?"
            ),
            "criteria": tool_criteria,
        },
    }


def build_cases(experiments: list[dict], spans: list[dict]) -> tuple[list[dict], collections.Counter]:
    briefs = {}
    for experiment in experiments:
        title = scrub(str(experiment.get("title") or ""))
        thesis = scrub(str(experiment.get("thesis") or ""))[:MAX_BRIEF_CHARS]
        briefs[experiment["id"]] = (title, thesis)

    by_session: dict[str, list[dict]] = collections.defaultdict(list)
    for span in spans:
        if span.get("tool_name"):
            by_session[span["sid"]].append(span)

    cases: list[dict] = []
    dropped = collections.Counter()

    for session_id, session_spans in by_session.items():
        session_spans.sort(key=lambda s: (s["start_time"], s["id"]))
        history: list[dict] = []

        for step_index, span in enumerate(session_spans):
            tool = span["tool_name"]
            detail = detail_for(tool, span.get("tool_params"))
            step = {"tool": tool, "detail": detail}

            domain = TOOL_TO_DOMAIN.get(tool)
            if domain is None:
                dropped["unmapped_tool"] += 1
                history.append(step)
                continue

            title, thesis = briefs.get(span["exp_id"], ("", ""))
            if not title and not thesis:
                dropped["no_task_text"] += 1
                history.append(step)
                continue

            state = {
                "task": title,
                "brief": thesis,
                "step_index": step_index,
                "steps_so_far": history[-MAX_STEPS_IN_STATE:],
            }

            serialised = json.dumps(state, ensure_ascii=False)
            if not is_clean(serialised):
                dropped["scrub_residue"] += 1
                history.append(step)
                continue

            case_id = "routing-" + hashlib.sha1(f"{session_id}:{span['id']}".encode()).hexdigest()[:12]

            cases.append(
                {
                    "id": case_id,
                    "state": state,
                    "questions": questions_for(domain),
                    "gold": {"domain": domain, "tool": tool},
                    "meta": {
                        "lang": "en",
                        "source": "phoenix:local_agent.tool",
                        "split": split_for(case_id),
                        "domain": domain,
                    },
                }
            )
            history.append(step)

    return cases, dropped


def stratify(cases: list[dict]) -> list[dict]:
    """Cap the two dominant domains, keep every case of the rare ones.

    Inside a capped domain the rare tools are kept whole and only the dominant
    tool is thinned, so the per-tool question keeps something to discriminate.
    """
    rng = random.Random(SAMPLE_SEED)
    by_domain: dict[str, list[dict]] = collections.defaultdict(list)
    for case in cases:
        by_domain[case["meta"]["domain"]].append(case)

    kept: list[dict] = []

    for domain, domain_cases in sorted(by_domain.items()):
        cap = DOMAIN_CAPS.get(domain)
        if cap is None or len(domain_cases) <= cap:
            kept.extend(domain_cases)
            continue

        by_tool: dict[str, list[dict]] = collections.defaultdict(list)
        for case in domain_cases:
            by_tool[case["gold"]["tool"]].append(case)

        # Biggest tool last, so the thinning lands on it.
        order = sorted(by_tool.items(), key=lambda kv: len(kv[1]))
        budget = cap
        for index, (_tool, tool_cases) in enumerate(order):
            remaining_groups = len(order) - index
            share = max(1, budget // remaining_groups) if remaining_groups > 1 else budget
            take = min(len(tool_cases), max(share, budget if remaining_groups == 1 else share))
            chosen = sorted(tool_cases, key=lambda c: c["id"])
            rng.shuffle(chosen)
            kept.extend(chosen[:take])
            budget -= take

    kept.sort(key=lambda c: c["id"])
    return kept


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--host", default="katsarov@195.201.195.167", help="ssh target running the Phoenix + app stacks")
    parser.add_argument("--cache-dir", default="/tmp/jev-export", help="where the raw dumps are written")
    parser.add_argument("--from-cache", action="store_true", help="skip ssh and rebuild from the dumps on disk")
    parser.add_argument(
        "--out",
        default=os.path.expanduser("~/jev-eval/datasets/fleetq/next-tool.jsonl"),
        help="dataset path to write",
    )
    parser.add_argument("--min-cases", type=int, default=500)
    args = parser.parse_args()

    cache_dir = pathlib.Path(args.cache_dir)

    if args.from_cache:
        experiments, spans = load_cache(cache_dir)
    else:
        experiments, spans = fetch(args.host, cache_dir)

    cases, dropped = build_cases(experiments, spans)
    kept = stratify(cases)

    out = pathlib.Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("w", encoding="utf-8") as handle:
        for case in kept:
            handle.write(json.dumps(case, ensure_ascii=False) + "\n")

    domains = collections.Counter(c["meta"]["domain"] for c in kept)
    tools = collections.Counter(c["gold"]["tool"] for c in kept)
    splits = collections.Counter(c["meta"]["split"] for c in kept)

    print(f"wrote {len(kept)} cases to {out}")
    print(f"  built {len(cases)} before stratification; dropped {dict(dropped)}")
    print(f"  domains  {dict(domains)}")
    print(f"  tools    {dict(tools)}")
    print(f"  splits   {dict(splits)}")

    if len(kept) < args.min_cases:
        print(f"ERROR: {len(kept)} cases is below the {args.min_cases} minimum", file=sys.stderr)
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
