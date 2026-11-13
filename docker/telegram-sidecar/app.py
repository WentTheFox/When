"""When's optional Telegram contacts sidecar.

Telegram only lets a *user account* (MTProto) read its contact list, so this small service logs in as the
owner with Telethon and answers a handful of JSON calls from When's backend. It is stateless on purpose:
the backend keeps the owner's session string (encrypted at rest with APP_KEY) and sends it with every call.
Nothing is written to disk and nothing is logged beyond a one-line access log: never a phone number, code,
password or session string.

Env: TELEGRAM_API_ID, TELEGRAM_API_HASH (from https://my.telegram.org), TELEGRAM_SIDECAR_TOKEN (shared bearer
secret with When), PORT (default 8080).
"""
import asyncio
import base64
import hmac
import json
import logging
import os
import sys
from contextlib import asynccontextmanager

from aiohttp import web
from telethon import TelegramClient, errors, functions, types
from telethon.sessions import StringSession

API_ID = int(os.environ.get("TELEGRAM_API_ID", "0"))
API_HASH = os.environ.get("TELEGRAM_API_HASH", "")
TOKEN = os.environ.get("TELEGRAM_SIDECAR_TOKEN", "")
MAX_PHOTOS = 40
# Downloads in flight at once. Photos live on different Telegram data centres, and each new one costs a handshake.
PHOTO_CONCURRENCY = 4

logging.basicConfig(level=logging.WARNING)

AUTH_LOST = (
    errors.AuthKeyUnregisteredError,
    errors.AuthKeyDuplicatedError,
    errors.SessionRevokedError,
    errors.SessionExpiredError,
    errors.UserDeactivatedError,
    errors.UserDeactivatedBanError,
)


def fail(status: int, code: str, message: str, **extra) -> web.Response:
    return web.json_response({"code": code, "message": message, **extra}, status=status)


@web.middleware
async def guard(request: web.Request, handler):
    if request.path == "/healthz":
        return await handler(request)
    given = request.headers.get("Authorization", "")
    if not TOKEN or not hmac.compare_digest(given, f"Bearer {TOKEN}"):
        return fail(401, "unauthorized", "Bad or missing sidecar token.")
    try:
        return await handler(request)
    except web.HTTPException:
        raise
    except (KeyError, TypeError, json.JSONDecodeError):
        # A missing field or a body that is not the JSON object we expect: the caller's mistake, not Telegram's.
        return fail(400, "bad_request", "The request is missing a field or is not valid JSON.")
    except AUTH_LOST:
        return fail(401, "auth_revoked", "The Telegram session is no longer valid.")
    except errors.FloodWaitError as e:
        return fail(429, "flood_wait", "Telegram asked us to slow down.", retry_after=e.seconds)
    except errors.PhoneNumberInvalidError:
        return fail(422, "invalid_phone", "Telegram does not accept that phone number.")
    except errors.PhoneCodeInvalidError:
        return fail(422, "invalid_code", "That code is not right.")
    except errors.PhoneCodeExpiredError:
        return fail(422, "code_expired", "That code expired. Start again to get a new one.")
    except errors.PasswordHashInvalidError:
        return fail(422, "invalid_password", "That two-step verification password is not right.")
    except (errors.RPCError, ValueError, ConnectionError, TimeoutError) as e:
        # The class name only: a message could echo what we were sent.
        return fail(502, "telegram_error", f"Telegram call failed ({type(e).__name__}).")


@asynccontextmanager
async def client_for(session: str):
    """A connected client for one request, always disconnected again.

    Deliberately NOT `async with TelegramClient(...)`: that calls start(), which prompts on stdin for a phone
    number whenever the session is not authorized yet, i.e. at the very first step of every login.
    """
    client = TelegramClient(StringSession(session or None), API_ID, API_HASH)
    await client.connect()
    try:
        yield client
    finally:
        await client.disconnect()


async def body(request: web.Request) -> dict:
    data = await request.json()
    if not isinstance(data, dict):
        raise web.HTTPBadRequest()
    return data


def account(me) -> dict:
    name = " ".join(p for p in [me.first_name, me.last_name] if p) or (me.username or str(me.id))
    return {"account_name": name, "user_id": str(me.id)}


async def login_start(request: web.Request) -> web.Response:
    data = await body(request)
    phone = str(data["phone"])
    async with client_for("") as client:
        sent = await client.send_code_request(phone)
        return web.json_response({"session": client.session.save(), "phone_code_hash": sent.phone_code_hash})


async def login_code(request: web.Request) -> web.Response:
    data = await body(request)
    session, phone, code, code_hash = (str(data[k]) for k in ("session", "phone", "code", "phone_code_hash"))
    async with client_for(session) as client:
        try:
            await client.sign_in(phone, code, phone_code_hash=code_hash)
        except errors.SessionPasswordNeededError:
            return web.json_response({"needs_password": True, "session": client.session.save()})
        return web.json_response({"needs_password": False, "session": client.session.save(), **account(await client.get_me())})


async def login_password(request: web.Request) -> web.Response:
    data = await body(request)
    session, password = str(data["session"]), str(data["password"])
    async with client_for(session) as client:
        await client.sign_in(password=password)
        return web.json_response({"session": client.session.save(), **account(await client.get_me())})


async def fetch_contacts(client: TelegramClient):
    result = await client(functions.contacts.GetContactsRequest(hash=0))
    return result.users if hasattr(result, "users") else []


async def contacts(request: web.Request) -> web.Response:
    data = await body(request)
    session = str(data["session"])
    async with client_for(session) as client:
        if not await client.is_user_authorized():
            return fail(401, "auth_revoked", "The Telegram session is no longer valid.")
        users = await fetch_contacts(client)
        out = {}
        for u in users:
            if getattr(u, "deleted", False):
                continue
            out[str(u.id)] = {
                "username": u.username,
                "first_name": u.first_name,
                "last_name": u.last_name,
                "phone": u.phone,
                "mutual": bool(getattr(u, "mutual_contact", False)),
                "premium": bool(getattr(u, "premium", False)),
                "bot": bool(getattr(u, "bot", False)),
                # Only a real UserProfilePhoto: the field can also be the "Empty" marker, which has nothing to download.
                "has_photo": isinstance(getattr(u, "photo", None), types.UserProfilePhoto),
            }
        return web.json_response({"contacts": out, "session": client.session.save()})


async def photos(request: web.Request) -> web.Response:
    """Profile-photo thumbnails for the asked contact ids.

    The reply says which ids were `checked` (asked about to the end): a checked id with no photo has none to
    give (hidden by its privacy settings, or no longer a contact), which is not the same as "not reached". One
    contact failing does not lose the others, and a flood wait stops further downloads but keeps what arrived,
    reporting `retry_after` so the caller backs off.
    """
    data = await body(request)
    wanted = {int(i) for i in list(data.get("ids", []))[:MAX_PHOTOS]}
    session = str(data["session"])
    async with client_for(session) as client:
        # Fetching the contact list fills the entity cache, which is what lets us address them by id.
        contacts_now = await fetch_contacts(client)
        users = [u for u in contacts_now if u.id in wanted]
        out: dict[str, str] = {}
        # Asked for but not (or no longer) in the contact list: nothing to fetch, and nothing to wait for.
        checked: list[str] = [str(i) for i in wanted - {u.id for u in contacts_now}]
        flood = 0
        gate = asyncio.Semaphore(PHOTO_CONCURRENCY)

        async def one(user) -> None:
            nonlocal flood
            async with gate:
                if flood:
                    return
                try:
                    raw = await client.download_profile_photo(user, file=bytes, download_big=False)
                except errors.FloodWaitError as e:
                    flood = max(flood, e.seconds)
                    return
                except AUTH_LOST:
                    raise
                except (errors.RPCError, ConnectionError, TimeoutError, asyncio.TimeoutError):
                    return  # Not checked: it is asked about again next time.
            checked.append(str(user.id))
            if raw:
                out[str(user.id)] = "data:image/jpeg;base64," + base64.b64encode(raw).decode()

        await asyncio.gather(*(one(u) for u in users))
        reply = {"photos": out, "checked": checked, "session": client.session.save()}
        if flood:
            reply["retry_after"] = flood
        return web.json_response(reply)


async def logout(request: web.Request) -> web.Response:
    data = await body(request)
    session = str(data["session"])
    async with client_for(session) as client:
        try:
            await client.log_out()
        except (errors.RPCError, ConnectionError):
            pass  # Already invalid is as good as logged out.
    return web.json_response({"ok": True})


async def healthz(_: web.Request) -> web.Response:
    return web.json_response({"ok": True})


def main() -> None:
    if not (API_ID and API_HASH and TOKEN):
        sys.exit("TELEGRAM_API_ID, TELEGRAM_API_HASH and TELEGRAM_SIDECAR_TOKEN must all be set.")
    app = web.Application(middlewares=[guard], client_max_size=64 * 1024)
    app.add_routes([
        web.get("/healthz", healthz),
        web.post("/v1/login/start", login_start),
        web.post("/v1/login/code", login_code),
        web.post("/v1/login/password", login_password),
        web.post("/v1/contacts", contacts),
        web.post("/v1/photos", photos),
        web.post("/v1/logout", logout),
    ])
    web.run_app(app, port=int(os.environ.get("PORT", "8080")), access_log=None)


if __name__ == "__main__":
    main()
