"""Espera a que llegue un email con un texto en el asunto (staging).

    python3 scripts/ci/wait_for_mail.py <puerto IMAP> <cuenta> <texto del asunto>

Lee por IMAP la cuenta de GreenMail (contraseña "secret") hasta 90 s.
"""

import email
import imaplib
import sys
import time

port, account, needle = int(sys.argv[1]), sys.argv[2], sys.argv[3]

for _ in range(45):
    try:
        imap = imaplib.IMAP4("127.0.0.1", port)
        imap.login(account, "secret")
        imap.select("INBOX")
        _, ids = imap.search(None, "ALL")
        for message_id in ids[0].split():
            _, data = imap.fetch(message_id, "(RFC822)")
            subject = email.message_from_bytes(data[0][1])["Subject"] or ""
            if needle in subject:
                print(f"  OK    aviso recibido por email: {subject}")
                sys.exit(0)
    except (OSError, imaplib.IMAP4.error):
        pass
    time.sleep(2)

print(f"No llegó ningún email con «{needle}» a {account}", file=sys.stderr)
sys.exit(1)
