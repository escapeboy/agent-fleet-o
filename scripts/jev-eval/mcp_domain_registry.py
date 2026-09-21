#!/usr/bin/env python3
"""The top-level MCP domain list, read out of the tool registry itself.

A domain is a directory under `app/Mcp/Tools/`; its option key is that directory
snake_cased, which is also the prefix its tools use. The one-sentence criteria
description comes from the registry in two ways, in order of preference:

1. The curated DOMAINS block in `AgentFleetServer::$instructions` — the server's
   own one-line description of a tool group, written for exactly this purpose.
2. Otherwise, the first sentence of the domain's canonical tool description
   (the `_list` tool where there is one, else the shortest-named tool), with a
   few of the group's tool names appended so the option is recognisable.

Nothing here is hand-written per domain: change the registry and this moves.
"""

from __future__ import annotations

import pathlib
import re

TOOLS_ROOT = pathlib.Path(__file__).resolve().parents[2] / "app" / "Mcp" / "Tools"
SERVER_FILE = pathlib.Path(__file__).resolve().parents[2] / "app" / "Mcp" / "Servers" / "AgentFleetServer.php"

NAME_RE = re.compile(r"protected\s+string\s+\$name\s*=\s*'([^']+)'")
DESC_RE = re.compile(
    r"protected\s+string\s+\$description\s*=\s*(?:'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\")",
    re.S,
)
CURATED_RE = re.compile(r"^\s{8}([a-z_]+)_\*+\s+(.+?)\s*$", re.M)


def snake(name: str) -> str:
    # Two passes so an acronym stays whole: RAGFlow -> rag_flow, not r_a_g_flow.
    name = re.sub(r"(.)([A-Z][a-z]+)", r"\1_\2", name)
    return re.sub(r"([a-z0-9])([A-Z])", r"\1_\2", name).lower()


def first_sentence(text: str) -> str:
    text = re.sub(r"\s+", " ", text).strip()
    match = re.match(r"^(.*?[.!?])(?:\s|$)", text)
    return (match.group(1) if match else text).strip()


def curated_lines() -> dict[str, str]:
    """The `prefix_*  description` lines from the server's own instructions."""
    if not SERVER_FILE.is_file():
        return {}

    source = SERVER_FILE.read_text(errors="replace")
    start = source.find("DOMAINS — use prefix")
    end = source.find("SEQUENCING", start) if start != -1 else -1

    if start == -1 or end == -1:
        return {}

    return {prefix: re.sub(r"\s+", " ", desc).strip() for prefix, desc in CURATED_RE.findall(source[start:end])}


def load() -> dict[str, dict]:
    """domain key -> {'description': str, 'tools': [(name, description)]}"""
    curated = curated_lines()
    registry: dict[str, dict] = {}

    for directory in sorted(p for p in TOOLS_ROOT.iterdir() if p.is_dir()):
        tools: list[tuple[str, str]] = []

        for file in sorted(directory.rglob("*.php")):
            source = file.read_text(errors="replace")
            name = NAME_RE.search(source)

            if not name:
                continue

            description = DESC_RE.search(source)
            raw = (description.group(1) or description.group(2)) if description else ""
            tools.append((name.group(1), re.sub(r"\s+", " ", raw.replace("\\'", "'")).strip()))

        if not tools:
            continue

        key = snake(directory.name)
        registry[key] = {"tools": tools, "description": describe(key, tools, curated)}

    return registry


def describe(key: str, tools: list[tuple[str, str]], curated: dict[str, str]) -> str:
    # The server's own line, and only on an exact key match. Matching on a
    # leading fragment instead would hand the `agent_*` description to
    # agent_session and agent_chat_protocol, which are separate routing targets.
    if key in curated:
        return f"{curated[key]} (tools: {', '.join(n for n, _ in tools[:3])})"

    listers = [t for t in tools if t[0].endswith("_list") and t[1]]
    described = [t for t in tools if t[1]]
    canonical = (listers or described or tools)[0]
    lead = first_sentence(canonical[1]) or f"Tools in the {key.replace('_', ' ')} group."

    return f"{lead} (tools: {', '.join(n for n, _ in tools[:3])})"


if __name__ == "__main__":
    registry = load()
    print(f"{len(registry)} domains, {sum(len(v['tools']) for v in registry.values())} tools")
    for key, value in registry.items():
        print(f"  {key:24} {value['description'][:110]}")
