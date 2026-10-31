"""Login del panel por HTTPS con la cookie de sesión de Sanctum (staging).

    python3 scripts/ci/panel_login.py https://localhost <email> <contraseña>

Como el navegador: pide la cookie CSRF, inicia sesión en /api/v1/auth/session
y comprueba /api/v1/auth/me. Con localhost acepta el certificado local de Caddy.
Las cookies se llevan a mano: el CookieJar de Python no devuelve las de
dominio "localhost" (sin punto).
"""

import json
import ssl
import sys
import urllib.parse
import urllib.request

base, email, password = sys.argv[1:4]
context = ssl.create_default_context()
if urllib.parse.urlparse(base).hostname == "localhost":
    context.check_hostname = False
    context.verify_mode = ssl.CERT_NONE

cookies: dict[str, str] = {}
flags: dict[str, str] = {}


def call(method: str, path: str, body: dict | None = None) -> dict:
    data = json.dumps(body).encode() if body is not None else None
    request = urllib.request.Request(base + path, data=data, method=method)
    request.add_header("Origin", base)
    request.add_header("Referer", base + "/login")
    request.add_header("Accept", "application/json")
    if data is not None:
        request.add_header("Content-Type", "application/json")
    if cookies:
        request.add_header("Cookie", "; ".join(f"{k}={v}" for k, v in cookies.items()))
    if "XSRF-TOKEN" in cookies:
        request.add_header("X-XSRF-TOKEN", urllib.parse.unquote(cookies["XSRF-TOKEN"]))
    with urllib.request.urlopen(request, timeout=15, context=context) as response:
        for header in response.headers.get_all("Set-Cookie") or []:
            name, _, rest = header.partition("=")
            cookies[name] = rest.split(";", 1)[0]
            flags[name] = rest.lower()
        text = response.read().decode()
        return json.loads(text) if text else {}


call("GET", "/sanctum/csrf-cookie")
session = next((name for name in cookies if name.endswith("-session")), None)
assert session is not None, "no hay cookie de sesión"
assert "; secure" in flags[session] and "httponly" in flags[session], "la cookie de sesión no es secure + httponly"
call("POST", "/api/v1/auth/session", {"email": email, "password": password})
me = call("GET", "/api/v1/auth/me")
assert me["data"]["email"] == email, me
print(f"  OK    login del panel por HTTPS como {email} ({me['data']['role']})")
