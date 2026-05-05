FROM python:3.11-slim

WORKDIR /app

COPY scripts/voice-worker/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY scripts/voice-worker/ .

CMD ["python", "worker.py", "daemon"]
