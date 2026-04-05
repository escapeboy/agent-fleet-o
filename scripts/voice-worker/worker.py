#!/usr/bin/env python3
"""
FleetQ Voice Worker — Redis-dispatched LiveKit agent.

Modes:
  daemon  — Wait for jobs from the PHP app via Redis BLPOP (recommended for cloud)
  session — Join a specific session by ID (for manual/local runs)

In daemon mode the worker reads a dispatch payload pushed by CreateVoiceSessionAction,
then spawns a livekit-agents VoicePipelineAgent for that room. Each session uses
per-team credentials from the payload — no shared env vars required.

Usage:
  python worker.py daemon
  python worker.py session --session-id <uuid> --room-name <name>
"""

import argparse
import asyncio
import json
import logging
import multiprocessing
import os
import signal
import sys

import httpx
import redis

from livekit import agents
from livekit.agents import AutoSubscribe, JobContext, WorkerOptions, cli
from livekit.agents.voice_assistant import VoiceAssistant
from livekit.plugins import deepgram, openai, elevenlabs, silero

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger("fleetq.voice-worker")


# ---------------------------------------------------------------------------
# Transcript posting
# ---------------------------------------------------------------------------

async def post_transcript(
    session_id: str,
    role: str,
    content: str,
    api_url: str,
    api_token: str,
) -> None:
    """Post a transcript turn back to the FleetQ API."""
    url = f"{api_url.rstrip('/')}/api/v1/voice-sessions/{session_id}/transcript"
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            await client.post(
                url,
                json={"role": role, "content": content},
                headers={"Authorization": f"Bearer {api_token}"},
            )
    except Exception as exc:
        logger.warning("Failed to post transcript turn: %s", exc)


# ---------------------------------------------------------------------------
# FleetQ LLM bridge — calls the FleetQ AI Gateway via HTTP
# ---------------------------------------------------------------------------

class FleetQAssistant:
    """Wraps FleetQ agent config for use in VoiceAssistant."""

    def __init__(self, payload: dict) -> None:
        self.session_id = payload["session_id"]
        self.api_url = payload["fleetq_api_url"]
        self.api_token = payload["fleetq_api_token"]
        self.agent_name = payload.get("agent_name", "Assistant")
        self.agent_role = payload.get("agent_role", "")
        self.agent_goal = payload.get("agent_goal", "")
        self.agent_backstory = payload.get("agent_backstory", "")

    def build_system_prompt(self) -> str:
        parts = []
        if self.agent_role:
            parts.append(f"Role: {self.agent_role}")
        if self.agent_goal:
            parts.append(f"Goal: {self.agent_goal}")
        if self.agent_backstory:
            parts.append(f"Background: {self.agent_backstory}")
        parts.append("You are a voice assistant. Keep responses concise and conversational.")
        return "\n\n".join(parts)


# ---------------------------------------------------------------------------
# Per-session agent entrypoint
# ---------------------------------------------------------------------------

async def run_session(ctx: JobContext, payload: dict) -> None:
    """Run the voice pipeline for a single LiveKit room."""
    logger.info("Starting voice session %s (room: %s)", payload["session_id"], payload["room_name"])

    await ctx.connect(auto_subscribe=AutoSubscribe.AUDIO_ONLY)

    assistant_config = FleetQAssistant(payload)

    # -- STT --
    stt_provider = payload.get("stt_provider", "deepgram")
    stt_api_key = payload.get("stt_api_key") or os.environ.get("DEEPGRAM_API_KEY", "")
    if stt_provider == "deepgram" and stt_api_key:
        stt = deepgram.STT(api_key=stt_api_key)
    else:
        # Fallback to OpenAI Whisper (uses stt_api_key as OpenAI key)
        openai_key = stt_api_key or os.environ.get("OPENAI_API_KEY", "")
        stt = openai.STT(api_key=openai_key)

    # -- TTS --
    tts_provider = payload.get("tts_provider", "openai")
    tts_api_key = payload.get("tts_api_key") or os.environ.get("ELEVENLABS_API_KEY", "")
    tts_voice_id = payload.get("tts_voice_id", "alloy")
    if tts_provider == "elevenlabs" and tts_api_key:
        tts = elevenlabs.TTS(api_key=tts_api_key, voice_id=tts_voice_id)
    else:
        openai_tts_key = tts_api_key or os.environ.get("OPENAI_API_KEY", "")
        tts = openai.TTS(api_key=openai_tts_key, voice=tts_voice_id)

    # -- LLM — fall back to direct OpenAI key --
    llm_key = os.environ.get("OPENAI_API_KEY", "")
    llm = openai.LLM(api_key=llm_key) if llm_key else None

    if llm is None:
        logger.error("No LLM configured — set OPENAI_API_KEY for the voice worker")
        return

    # -- VAD --
    vad = silero.VAD.load()

    assistant = VoiceAssistant(
        vad=vad,
        stt=stt,
        llm=llm,
        tts=tts,
        chat_ctx=agents.llm.ChatContext().append(
            role="system",
            text=assistant_config.build_system_prompt(),
        ),
    )

    # Transcript hooks
    @assistant.on("user_speech_committed")
    def on_user_speech(user_msg: agents.llm.ChatMessage) -> None:
        asyncio.ensure_future(post_transcript(
            session_id=payload["session_id"],
            role="user",
            content=user_msg.content,
            api_url=payload["fleetq_api_url"],
            api_token=payload["fleetq_api_token"],
        ))

    @assistant.on("agent_speech_committed")
    def on_agent_speech(agent_msg: agents.llm.ChatMessage) -> None:
        asyncio.ensure_future(post_transcript(
            session_id=payload["session_id"],
            role="agent",
            content=agent_msg.content,
            api_url=payload["fleetq_api_url"],
            api_token=payload["fleetq_api_token"],
        ))

    assistant.start(ctx.room)
    await assistant.say(
        f"Hi, I'm {assistant_config.agent_name}. How can I help you today?",
        allow_interruptions=True,
    )
    await asyncio.sleep(float("inf"))


# ---------------------------------------------------------------------------
# Daemon mode — Redis BLPOP dispatch
# ---------------------------------------------------------------------------

def daemon_mode() -> None:
    """
    Listen for voice session jobs pushed by the PHP app (LIVEKIT_WORKER_DISPATCH=true).

    Payload schema (JSON):
      session_id, room_name, livekit_url, livekit_api_key, livekit_api_secret,
      stt_provider, stt_api_key, tts_provider, tts_api_key, tts_voice_id,
      fleetq_api_url, fleetq_api_token, agent_name, agent_role, agent_goal, agent_backstory
    """
    redis_url = os.environ.get("REDIS_URL", "redis://localhost:6379/0")
    r = redis.from_url(redis_url, decode_responses=True)
    logger.info("Voice worker daemon started — listening on Redis queue 'voice_worker_dispatch'")
    logger.info("Redis: %s", redis_url.split("@")[-1])  # hide credentials

    running = True

    def stop(_sig, _frame):
        nonlocal running
        running = False
        logger.info("Shutting down voice worker daemon...")

    signal.signal(signal.SIGINT, stop)
    signal.signal(signal.SIGTERM, stop)

    while running:
        try:
            item = r.blpop("voice_worker_dispatch", timeout=5)
            if item is None:
                continue

            _, raw = item
            payload = json.loads(raw)
            logger.info("Received session dispatch: %s", payload.get("session_id"))

            proc = multiprocessing.Process(
                target=_run_session_process,
                args=(payload,),
                daemon=True,
            )
            proc.start()

        except redis.exceptions.ConnectionError as exc:
            logger.error("Redis connection error: %s — retrying in 5s", exc)
            import time
            time.sleep(5)
        except Exception as exc:
            logger.error("Unexpected error in dispatch loop: %s", exc)


def _run_session_process(payload: dict) -> None:
    """Run a single session in its own process."""
    session_id = payload.get("session_id", "unknown")
    room_name = payload.get("room_name", "unknown")

    logger.info("[%s] Joining LiveKit room %s @ %s", session_id, room_name, payload["livekit_url"])

    async def entrypoint(ctx: JobContext) -> None:
        await run_session(ctx, payload)

    try:
        cli.run_app(WorkerOptions(
            entrypoint_fnc=entrypoint,
            ws_url=payload["livekit_url"],
            api_key=payload["livekit_api_key"],
            api_secret=payload["livekit_api_secret"],
        ))
    except Exception as exc:
        logger.error("[%s] Session error: %s", session_id, exc)


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(description="FleetQ Voice Worker")
    subparsers = parser.add_subparsers(dest="mode", required=True)

    subparsers.add_parser("daemon", help="Listen for jobs via Redis (cloud deployment)")

    session_parser = subparsers.add_parser("session", help="Join a single session directly")
    session_parser.add_argument("--session-id", required=True)
    session_parser.add_argument("--room-name", required=True)
    session_parser.add_argument("--livekit-url", default=os.environ.get("LIVEKIT_URL", ""))
    session_parser.add_argument("--api-token", default=os.environ.get("FLEETQ_API_TOKEN", ""))
    session_parser.add_argument("--fleetq-url", default=os.environ.get("FLEETQ_BASE_URL", ""))

    args = parser.parse_args()

    if args.mode == "daemon":
        daemon_mode()
    elif args.mode == "session":
        payload = {
            "session_id": args.session_id,
            "room_name": args.room_name,
            "livekit_url": args.livekit_url or os.environ.get("LIVEKIT_URL", ""),
            "livekit_api_key": os.environ.get("LIVEKIT_API_KEY", ""),
            "livekit_api_secret": os.environ.get("LIVEKIT_API_SECRET", ""),
            "stt_provider": os.environ.get("VOICE_STT_PROVIDER", "deepgram"),
            "stt_api_key": os.environ.get("DEEPGRAM_API_KEY", ""),
            "tts_provider": os.environ.get("VOICE_TTS_PROVIDER", "openai"),
            "tts_api_key": os.environ.get("ELEVENLABS_API_KEY", ""),
            "tts_voice_id": os.environ.get("VOICE_TTS_VOICE_ID", "alloy"),
            "fleetq_api_url": args.fleetq_url,
            "fleetq_api_token": args.api_token,
            "agent_name": "Assistant",
            "agent_role": "",
            "agent_goal": "",
            "agent_backstory": "",
        }

        async def entrypoint(ctx: JobContext) -> None:
            await run_session(ctx, payload)

        cli.run_app(WorkerOptions(entrypoint_fnc=entrypoint))


if __name__ == "__main__":
    main()
