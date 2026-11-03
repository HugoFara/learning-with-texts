FROM python:3.14-slim-trixie

# Install system dependencies for MeCab, Piper and Whisper.
# openai-whisper shells out to ffmpeg for audio decoding; without it
# every transcribe job dies with "[Errno 2] No such file or directory:
# 'ffmpeg'" no matter how healthy the model load looks.
RUN apt-get update && apt-get install -y --no-install-recommends \
    mecab mecab-ipadic-utf8 libmecab-dev \
    ffmpeg \
    curl \
    && rm -rf /var/lib/apt/lists/*

# The mecab-python3 wheel bundles a libmecab built for /usr/local, so it
# looks for /usr/local/etc/mecabrc; Debian's mecab package puts it in /etc.
# Without this, MeCab.Tagger() raises and every Japanese parse fails.
ENV MECABRC=/etc/mecabrc

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app/ ./app/

# Create voices directory
RUN mkdir -p /app/voices

EXPOSE 8000

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
