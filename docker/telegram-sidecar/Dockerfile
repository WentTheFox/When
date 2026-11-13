# Optional Telegram contacts sidecar for When. Stateless: every request carries the owner's Telethon
# StringSession and the reply carries the (possibly rotated) session back; nothing is stored here.
#   docker build -t when-telegram-sidecar docker/telegram-sidecar
FROM python:3.13-slim
ENV PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY app.py .
RUN useradd --system --uid 10001 sidecar
USER 10001
EXPOSE 8080
CMD ["python", "app.py"]
