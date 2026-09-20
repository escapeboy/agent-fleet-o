#!/usr/bin/env python3
"""Build the FleetQ domain-routing eval dataset.

Unlike next-tool.jsonl, nothing here is scored against what an agent decided to
do. Every gold answer comes from configuration or from an explicit human
choice already recorded in the database:

  assistant_turn    a user asked the platform assistant for something and the
                    assistant called tools from exactly one MCP domain in that
                    turn. Turns touching two or more domains are dropped — the
                    gold would be ambiguous.
  signal_workflow   a signal was routed into an experiment whose workflow
                    template was selected by configuration.
  experiment_agent  a human created an experiment and assigned a specific agent
                    to it, with no workflow.

`model_tier` is attached only where it can be read off a recorded outcome: the
first AI run for that experiment that completed with no tier escalation. Cases
with no such run get the domain question alone.

Synthetic top-up cases go to a SEPARATE file and are never mixed in.

Usage
-----
  python3 export_routing_v2_dataset.py --host katsarov@195.201.195.167
  python3 export_routing_v2_dataset.py --from-cache --cache-dir /tmp/jev-export
"""

from __future__ import annotations

import argparse
import collections
import hashlib
import json
import os
import pathlib
import re
import subprocess
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

import mcp_domain_registry  # noqa: E402
from export_next_tool_dataset import is_clean, scrub, split_for  # noqa: E402

MAX_REQUEST_CHARS = 2000
MAX_CASES_PER_SOURCE_TASK = 15
MIN_DISTINCT_TASKS = 40

OTHER_DESCRIPTION = "A request none of the domains above handles"

# An assistant tool name to the MCP domain that owns the same capability. Built
# from the names the assistant registry actually emitted; a name that does not
# resolve drops its case rather than guessing a domain.
ASSISTANT_TOOL_DOMAIN = {
    "activate_project": "project",
    "activate_workflow": "workflow",
    "add_agent_to_crew": "crew",
    "agent_create": "agent",
    "agent_delete": "agent",
    "agent_list": "agent",
    "create_agent": "agent",
    "create_crew": "crew",
    "create_experiment": "experiment",
    "create_project": "project",
    "create_skill": "skill",
    "create_website_page": "website",
    "create_workflow": "workflow",
    "crew_activate": "crew",
    "crew_delete": "crew",
    "crew_executions_list": "crew",
    "custom_endpoint_manage": "integration",
    "design_crew": "crew",
    "execute_crew": "crew",
    "experiment_diagnose": "experiment",
    "generate_workflow": "workflow",
    "get_crew": "crew",
    "get_experiment": "experiment",
    "get_project": "project",
    "get_workflow": "workflow",
    "integration_capabilities": "integration",
    "integration_list": "integration",
    "kill_experiment": "experiment",
    "list_agents": "agent",
    "list_crews": "crew",
    "list_experiments": "experiment",
    "list_projects": "project",
    "list_website_pages": "website",
    "list_websites": "website",
    "list_workflows": "workflow",
    "memory_add": "memory",
    "model_catalog": "system",
    "publish_website_page": "website",
    "resume_experiment": "experiment",
    "retry_experiment": "experiment",
    "save_workflow_graph": "workflow",
    "search_memories": "memory",
    "start_experiment": "experiment",
    "team_byok_credential_manage": "credential",
    "tool_list": "tool",
    "trigger_project_run": "project",
    "update_agent": "agent",
    "update_website_page": "website",
    "upload_memory_knowledge": "memory",
}

# Model id to tier. `code_only` is a local coding agent billed at zero cost;
# `small_model` is a cheap hosted model; `frontier` is a flagship.
TIER_PATTERNS = [
    ("code_only", re.compile(r"(claude-code|codex|bridge_agent|local)", re.I)),
    ("small_model", re.compile(r"(haiku|mini|flash|lite|small|free|llama|gpt-oss|deepseek)", re.I)),
    ("frontier", re.compile(r"(sonnet|opus|gpt-4o|gpt-5|gemini-[\d.]+-pro|-pro\b|inkling)", re.I)),
]

TIER_DESCRIPTIONS = {
    "code_only": "A local coding agent running on the machine, at no per-token cost",
    "small_model": "A small, cheap hosted model",
    "frontier": "A flagship hosted model",
}

SQL = """
select json_build_object(
 'assistant', (select coalesce(json_agg(row_to_json(a)),'[]'::json) from (
    select m.id, m.conversation_id, m.created_at, m.tool_calls, c.title as conversation_title,
           (select u.content from assistant_messages u
             where u.conversation_id = m.conversation_id and u.role='user' and u.created_at <= m.created_at
             order by u.created_at desc limit 1) as user_request
    from assistant_messages m join assistant_conversations c on c.id = m.conversation_id
    where m.role='assistant' and m.tool_calls is not null and jsonb_typeof(m.tool_calls)='array'
      and jsonb_array_length(m.tool_calls) > 0 order by m.created_at) a),
 'signal_workflow', (select coalesce(json_agg(row_to_json(s)),'[]'::json) from (
    select sg.id, sg.source_type, sg.payload, sg.created_at, e.id as experiment_id,
           e.title as experiment_title, w.id as workflow_id, w.name as workflow_name
    from signals sg join experiments e on e.id = sg.experiment_id
    join workflows w on w.id = e.workflow_id) s),
 'experiment_agent', (select coalesce(json_agg(row_to_json(x)),'[]'::json) from (
    select e.id, e.title, e.thesis, e.created_at, ag.id as agent_id, ag.name as agent_name, ag.role as agent_role
    from experiments e join agents ag on ag.id = e.agent_id where e.workflow_id is null) x),
 'tier_runs', (select coalesce(json_agg(row_to_json(r)),'[]'::json) from (
    select experiment_id, provider, model, status, escalation_attempts, created_at
    from ai_runs where experiment_id is not null and status='completed' and escalation_attempts = 0
    order by created_at) r)
)::text;
"""


def fetch(host: str, cache_dir: pathlib.Path) -> dict:
    cache_dir.mkdir(parents=True, exist_ok=True)
    command = f'docker exec agent-fleet-postgres psql -U agent_fleet -d agent_fleet -P pager=off -At -c "{SQL}"'
    result = subprocess.run(
        ["ssh", "-o", "ConnectTimeout=60", "-o", "BatchMode=yes", host, command],
        capture_output=True,
        text=True,
        check=True,
    )
    payload = result.stdout[result.stdout.index('{"') :]
    (cache_dir / "rv2_raw.json").write_text(payload)
    return json.loads(payload)


def load_cache(cache_dir: pathlib.Path) -> dict:
    text = (cache_dir / "rv2_raw.json").read_text()
    return json.loads(text[text.index('{"') :])


def tier_for(model: str) -> str | None:
    for tier, pattern in TIER_PATTERNS:
        if pattern.search(model or ""):
            return tier
    return None


def tier_index(tier_runs: list[dict]) -> dict[str, str]:
    """experiment id -> tier of its first clean successful run."""
    index: dict[str, str] = {}
    for run in tier_runs:
        experiment = run.get("experiment_id")
        if experiment is None or experiment in index:
            continue
        tier = tier_for(str(run.get("model") or ""))
        if tier is not None:
            index[experiment] = tier
    return index


def questions(registry: dict[str, dict], with_tier: bool) -> dict:
    criteria = {key: value["description"] for key, value in registry.items()}
    criteria["other"] = OTHER_DESCRIPTION

    built = {
        "domain": {
            "type": "choice",
            "instructions": (
                "`request` is what someone asked FleetQ to do. Which FleetQ tool domain "
                "handles it?"
            ),
            "criteria": criteria,
        }
    }

    if with_tier:
        tier_criteria = dict(TIER_DESCRIPTIONS)
        tier_criteria["other"] = "A tier none of the options above describes"
        built["model_tier"] = {
            "type": "choice",
            "instructions": "Which size of model is enough to carry out `request` on the first attempt?",
            "criteria": tier_criteria,
        }

    return built


def case_id(prefix: str, seed: str) -> str:
    return f"{prefix}-" + hashlib.sha1(seed.encode()).hexdigest()[:12]


def task_key(prefix: str, seed: str) -> str:
    """An opaque, stable grouping key.

    The 15-per-task cap and the distinct-task count both need to know which
    cases share an origin, but the origin itself is often a tenant row id. This
    keeps the grouping and drops the identifier.
    """
    return f"{prefix}:" + hashlib.sha1(seed.encode()).hexdigest()[:12]


def guess_lang(text: str) -> str:
    return "bg" if re.search(r"[Ѐ-ӿ]", text) else "en"


def payload_text(payload) -> str:
    """The human-readable part of a signal payload, without its plumbing."""
    if isinstance(payload, str):
        return payload
    if not isinstance(payload, dict):
        return json.dumps(payload, ensure_ascii=False)

    parts = []
    for key in ("title", "summary", "message", "description", "culprit", "body", "text", "name"):
        value = payload.get(key)
        if isinstance(value, str) and value.strip():
            parts.append(f"{key}: {value.strip()}")
    return "\n".join(parts) or json.dumps(payload, ensure_ascii=False)


def build(data: dict, registry: dict[str, dict]) -> tuple[list[dict], collections.Counter]:
    tiers = tier_index(data.get("tier_runs", []))
    dropped: collections.Counter = collections.Counter()
    per_task: collections.Counter = collections.Counter()
    cases: list[dict] = []

    def emit(case: dict, task_key: str) -> None:
        if per_task[task_key] >= MAX_CASES_PER_SOURCE_TASK:
            dropped["task_cap"] += 1
            return
        per_task[task_key] += 1
        cases.append(case)

    # --- assistant turns ----------------------------------------------------
    for message in data.get("assistant", []):
        names = [call.get("toolName") or call.get("name") for call in message.get("tool_calls") or []]
        names = [n for n in names if isinstance(n, str)]

        if not names:
            dropped["assistant_no_tool_name"] += 1
            continue

        domains = {ASSISTANT_TOOL_DOMAIN.get(n) for n in names}

        if None in domains:
            dropped["assistant_unmapped_tool"] += 1
            continue
        if len(domains) != 1:
            dropped["assistant_multi_domain"] += 1
            continue

        domain = domains.pop()

        if domain not in registry:
            dropped["assistant_domain_not_in_registry"] += 1
            continue

        request = scrub(str(message.get("user_request") or message.get("conversation_title") or ""))[:MAX_REQUEST_CHARS]

        if not request:
            dropped["assistant_no_request"] += 1
            continue

        state = {"request": request, "origin": "platform assistant chat"}

        if not is_clean(json.dumps(state, ensure_ascii=False)):
            dropped["scrub_residue"] += 1
            continue

        identifier = case_id("rv2-asst", str(message["id"]))
        emit(
            {
                "id": identifier,
                "state": state,
                "questions": questions(registry, with_tier=False),
                "gold": {"domain": domain},
                "meta": {
                    "lang": guess_lang(request),
                    "source": "assistant_turn",
                    "split": split_for(identifier),
                    "task_id": task_key("conversation", str(message["conversation_id"])),
                    "gold_basis": "MCP domain of the tools the assistant called in this turn",
                },
            },
            task_key("conversation", str(message["conversation_id"])),
        )

    # --- signals routed into a configured workflow --------------------------
    for signal in data.get("signal_workflow", []):
        request = scrub(payload_text(signal.get("payload")))[:MAX_REQUEST_CHARS]

        if not request:
            dropped["signal_no_payload_text"] += 1
            continue

        state = {"request": request, "origin": f"inbound signal from {signal.get('source_type')}"}

        if not is_clean(json.dumps(state, ensure_ascii=False)):
            dropped["scrub_residue"] += 1
            continue

        identifier = case_id("rv2-sig", str(signal["id"]))
        tier = tiers.get(signal.get("experiment_id"))
        gold = {"domain": "workflow"}

        if tier:
            gold["model_tier"] = tier

        emit(
            {
                "id": identifier,
                "state": state,
                "questions": questions(registry, with_tier=bool(tier)),
                "gold": gold,
                "meta": {
                    "lang": guess_lang(request),
                    "source": "signal_workflow",
                    "split": split_for(identifier),
                    "task_id": task_key("bug", scrub(str(signal.get("experiment_title") or signal["id"]))),
                    "gold_basis": f"configured workflow template '{signal.get('workflow_name')}'",
                },
            },
            task_key("bug", scrub(str(signal.get("experiment_title") or signal["id"]))),
        )

    # --- experiments a human assigned to a specific agent -------------------
    for experiment in data.get("experiment_agent", []):
        title = scrub(str(experiment.get("title") or ""))
        thesis = scrub(str(experiment.get("thesis") or ""))
        request = (f"{title}\n\n{thesis}").strip()[:MAX_REQUEST_CHARS]

        if not request:
            dropped["experiment_no_text"] += 1
            continue

        state = {"request": request, "origin": "experiment created in the console"}

        if not is_clean(json.dumps(state, ensure_ascii=False)):
            dropped["scrub_residue"] += 1
            continue

        identifier = case_id("rv2-exp", str(experiment["id"]))
        tier = tiers.get(experiment["id"])
        gold = {"domain": "agent"}

        if tier:
            gold["model_tier"] = tier

        emit(
            {
                "id": identifier,
                "state": state,
                "questions": questions(registry, with_tier=bool(tier)),
                "gold": gold,
                "meta": {
                    "lang": guess_lang(request),
                    "source": "experiment_agent",
                    "split": split_for(identifier),
                    "task_id": task_key("experiment", title or str(experiment["id"])),
                    "gold_basis": f"agent '{scrub(str(experiment.get('agent_name') or ''))}' assigned by a human",
                },
            },
            task_key("experiment", title or str(experiment["id"])),
        )

    return cases, dropped


def synthesise(registry: dict[str, dict], existing: int, target: int) -> list[dict]:
    """One case per registry tool description, until the shortfall is covered.

    The request is the tool's own description turned into a first-person ask, so
    the text comes from the registry rather than from anything invented. Round
    robin over domains so the file does not fill up with whichever domain has
    the most tools.
    """
    shortfall = max(0, target - existing)

    if shortfall == 0:
        return []

    queues = {
        key: [(name, description) for name, description in value["tools"] if description]
        for key, value in registry.items()
    }
    order = sorted(k for k, v in queues.items() if v)
    cases: list[dict] = []
    round_index = 0

    while len(cases) < shortfall and order:
        progressed = False

        for domain in order:
            if len(cases) >= shortfall:
                break
            if round_index >= len(queues[domain]):
                continue

            progressed = True
            name, description = queues[domain][round_index]
            sentence = mcp_domain_registry.first_sentence(description)
            request = f"I need to do this in FleetQ: {sentence[0].lower() + sentence[1:]}"
            identifier = case_id("rv2-syn", f"{domain}:{name}")

            cases.append(
                {
                    "id": identifier,
                    "state": {"request": request, "origin": "synthetic, generated from the MCP tool registry"},
                    "questions": questions(registry, with_tier=False),
                    "gold": {"domain": domain},
                    "meta": {
                        "lang": "en",
                        "source": "synthetic",
                        "split": split_for(identifier),
                        "task_id": f"synthetic:{domain}:{name}",
                        "gold_basis": f"registry domain of tool '{name}'",
                    },
                }
            )

        if not progressed:
            break

        round_index += 1

    return cases


def write(path: pathlib.Path, cases: list[dict]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as handle:
        for case in sorted(cases, key=lambda c: c["id"]):
            handle.write(json.dumps(case, ensure_ascii=False) + "\n")


def summarise(label: str, cases: list[dict]) -> None:
    domains = collections.Counter(c["gold"]["domain"] for c in cases)
    sources = collections.Counter(c["meta"]["source"] for c in cases)
    splits = collections.Counter(c["meta"]["split"] for c in cases)
    tasks = {c["meta"]["task_id"] for c in cases}
    tiers = collections.Counter(c["gold"]["model_tier"] for c in cases if "model_tier" in c["gold"])

    print(f"{label}: {len(cases)} cases, {len(tasks)} distinct source tasks")
    print(f"  sources   {dict(sources)}")
    print(f"  splits    {dict(splits)}")
    print(f"  domains   {dict(domains.most_common())}")
    print(f"  model_tier gold on {sum(tiers.values())} cases {dict(tiers)}")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--host", default="katsarov@195.201.195.167")
    parser.add_argument("--cache-dir", default="/tmp/jev-export")
    parser.add_argument("--from-cache", action="store_true")
    parser.add_argument("--out-dir", default=os.path.expanduser("~/jev-eval/datasets/fleetq"))
    parser.add_argument("--target", type=int, default=300, help="combined real + synthetic case target")
    args = parser.parse_args()

    cache_dir = pathlib.Path(args.cache_dir)
    data = load_cache(cache_dir) if args.from_cache else fetch(args.host, cache_dir)
    registry = mcp_domain_registry.load()

    real, dropped = build(data, registry)
    synthetic = synthesise(registry, len(real), args.target)

    out_dir = pathlib.Path(args.out_dir)
    write(out_dir / "routing-v2.jsonl", real)
    write(out_dir / "routing-v2-synth.jsonl", synthetic)

    print(f"registry: {len(registry)} domains + other")
    summarise("routing-v2.jsonl (real)", real)
    summarise("routing-v2-synth.jsonl (synthetic)", synthetic)
    print(f"  dropped   {dict(dropped)}")

    tasks = {c["meta"]["task_id"] for c in real}

    if len(tasks) < MIN_DISTINCT_TASKS:
        print(f"ERROR: {len(tasks)} distinct source tasks is below the {MIN_DISTINCT_TASKS} minimum", file=sys.stderr)
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
