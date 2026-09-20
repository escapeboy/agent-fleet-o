#!/usr/bin/env python3
"""Build the multi-label domain-prefilter dataset.

The question this dataset asks is not "which one domain handles this" but
"which domains can be ruled out" — the shape a prefilter in front of a 700-tool
MCP server actually needs. One Noul per domain, all 67 in a single request,
gold true for every domain the assistant drew a tool from in that turn.

Multi-domain turns are kept here, unlike routing-v2, because a turn that spans
two domains is a correct multi-label answer rather than an ambiguous one.

Usage
-----
  python3 export_domain_prefilter_dataset.py --host katsarov@195.201.195.167
  python3 export_domain_prefilter_dataset.py --from-cache --cache-dir /tmp/jev-export
"""

from __future__ import annotations

import argparse
import collections
import json
import os
import pathlib
import subprocess
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

import mcp_domain_registry  # noqa: E402
from export_next_tool_dataset import is_clean, scrub, split_for  # noqa: E402
from export_routing_v2_dataset import (  # noqa: E402
    ASSISTANT_TOOL_DOMAIN,
    case_id,
    guess_lang,
    task_key,
)

MAX_TURNS_PER_CONVERSATION = 10
MAX_PRIOR_TURNS = 2
MAX_TEXT_CHARS = 2000
MIN_USABLE_TURNS = 200

SQL = """
select coalesce(json_agg(row_to_json(a) order by a.conversation_id, a.created_at), '[]'::json)::text from (
  select m.id, m.conversation_id, m.created_at, m.tool_calls,
         (select json_agg(json_build_object('role', p.role, 'content', p.content, 'created_at', p.created_at) order by p.created_at desc)
            from (select role, content, created_at from assistant_messages x
                   where x.conversation_id = m.conversation_id and x.created_at < m.created_at
                     and x.role in ('user','assistant')
                   order by x.created_at desc limit 3) p) as prior
  from assistant_messages m
  where m.role='assistant' and m.tool_calls is not null and jsonb_typeof(m.tool_calls)='array'
    and jsonb_array_length(m.tool_calls) > 0
) a;
"""


def fetch(host: str, cache_dir: pathlib.Path) -> list[dict]:
    cache_dir.mkdir(parents=True, exist_ok=True)
    command = f'docker exec agent-fleet-postgres psql -U agent_fleet -d agent_fleet -P pager=off -At -c "{SQL}"'
    result = subprocess.run(
        ["ssh", "-o", "ConnectTimeout=60", "-o", "BatchMode=yes", host, command],
        capture_output=True,
        text=True,
        check=True,
    )
    payload = result.stdout[result.stdout.index("[") :]
    (cache_dir / "prefilter_raw.json").write_text(payload)
    return json.loads(payload)


def load_cache(cache_dir: pathlib.Path) -> list[dict]:
    text = (cache_dir / "prefilter_raw.json").read_text()
    return json.loads(text[text.index("[") :])


def questions(registry: dict[str, dict]) -> dict:
    """One Noul per domain. Noul criteria are a {true, false} pair on the wire,
    so the registry description becomes what a yes means."""
    built = {}

    for slug, value in registry.items():
        built[slug] = {
            "type": "noul",
            "instructions": f"Answering this request requires tools from the {slug} domain.",
            "criteria": {
                "true": value["description"],
                "false": "The request is served by a different domain.",
            },
        }

    return built


def build(turns: list[dict], registry: dict[str, dict]) -> tuple[list[dict], collections.Counter]:
    dropped: collections.Counter = collections.Counter()
    per_conversation: collections.Counter = collections.Counter()
    question_set = questions(registry)
    cases: list[dict] = []

    for turn in turns:
        conversation = str(turn["conversation_id"])

        if per_conversation[conversation] >= MAX_TURNS_PER_CONVERSATION:
            dropped["conversation_cap"] += 1
            continue

        names = [call.get("toolName") or call.get("name") for call in turn.get("tool_calls") or []]
        names = [n for n in names if isinstance(n, str)]
        domains = {ASSISTANT_TOOL_DOMAIN.get(n) for n in names}

        if not names or None in domains:
            dropped["unmapped_tool"] += 1
            continue

        domains = {d for d in domains if d in registry}

        if not domains:
            dropped["domain_not_in_registry"] += 1
            continue

        # The prior turns come back newest-first; the state reads oldest-first.
        prior = list(reversed(turn.get("prior") or []))
        user_turns = [p for p in prior if p.get("role") == "user"]

        if not user_turns:
            dropped["no_user_message"] += 1
            continue

        request = scrub(str(user_turns[-1].get("content") or ""))[:MAX_TEXT_CHARS]

        if not request:
            dropped["empty_request"] += 1
            continue

        context = []

        for entry in prior[:-1][-MAX_PRIOR_TURNS:]:
            text = scrub(str(entry.get("content") or ""))[:MAX_TEXT_CHARS]

            if text:
                context.append({"role": entry.get("role"), "text": text})

        state = {"request": request, "preceding_turns": context}

        if not is_clean(json.dumps(state, ensure_ascii=False)):
            dropped["scrub_residue"] += 1
            continue

        identifier = case_id("prefilter", str(turn["id"]))
        per_conversation[conversation] += 1

        cases.append(
            {
                "id": identifier,
                "state": state,
                "questions": question_set,
                "gold": {slug: slug in domains for slug in registry},
                "meta": {
                    "lang": guess_lang(request),
                    "source": "assistant_turn_multilabel",
                    "split": split_for(identifier),
                    "task_id": task_key("conversation", conversation),
                    "gold_basis": "MCP domains of every tool the assistant called in this turn",
                    "gold_domains": sorted(domains),
                },
            }
        )

    return cases, dropped


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--host", default="katsarov@195.201.195.167")
    parser.add_argument("--cache-dir", default="/tmp/jev-export")
    parser.add_argument("--from-cache", action="store_true")
    parser.add_argument("--out", default=os.path.expanduser("~/jev-eval/datasets/fleetq/domain-prefilter.jsonl"))
    args = parser.parse_args()

    cache_dir = pathlib.Path(args.cache_dir)
    turns = load_cache(cache_dir) if args.from_cache else fetch(args.host, cache_dir)
    registry = mcp_domain_registry.load()

    cases, dropped = build(turns, registry)

    out = pathlib.Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)

    with out.open("w", encoding="utf-8") as handle:
        for case in sorted(cases, key=lambda c: c["id"]):
            handle.write(json.dumps(case, ensure_ascii=False) + "\n")

    label_counts = collections.Counter(d for c in cases for d in c["meta"]["gold_domains"])
    set_sizes = collections.Counter(len(c["meta"]["gold_domains"]) for c in cases)
    splits = collections.Counter(c["meta"]["split"] for c in cases)

    print(f"wrote {len(cases)} cases to {out}")
    print(f"  candidate turns   {len(turns)}")
    print(f"  labels per case   {dict(sorted(set_sizes.items()))}")
    print(f"  splits            {dict(splits)}")
    print(f"  distinct tasks    {len({c['meta']['task_id'] for c in cases})}")
    print(f"  domains seen      {dict(label_counts.most_common())}")
    print(f"  dropped           {dict(dropped)}")

    if len(cases) < MIN_USABLE_TURNS:
        print(
            f"\nUNDERPOWERED: {len(cases)} usable turns is below the {MIN_USABLE_TURNS} minimum. "
            "No synthetic cases were generated.",
            file=sys.stderr,
        )
        return 2

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
