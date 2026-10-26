# Optional email on localhost

EasySched requires PostgreSQL for application data. Registration verification, password recovery, and changing an account email address also need internet access and an email provider. Existing accounts can sign in, change their passwords, and use scheduling without email. Administrators can create accounts through Academic setup.

1. Copy .env.example to .env in the EasySched folder. Keep .env private.
2. Uncomment the SMTP settings and enter your email address and app password, or configure Resend with an API key and verified sender. Do not use your normal email password as an app password.
3. For SMTP TLS, enable PHP OpenSSL; for Resend, enable PHP curl in XAMPP. Restart Apache after changing PHP extensions.
4. Open EasySched. The PostgreSQL schema, including the OTP table, is initialized automatically.

Without email configuration, code requests report that delivery is not configured; verification is never bypassed. OTP codes expire after ten minutes, allow five attempts, and can be requested only once per minute. Only their hashes are stored. Users can view their current address and request an email change from **Settings**; the code is sent to the new address. An administrator can update an account's email directly from **Academic setup → Users → Edit** when the user has lost access to their old address. Admin changes are audited.

## Contact form delivery

Set `EASYSCHED_EMAIL_FROM` to the verified sender used for OTP and contact-form notifications, and set `EASYSCHED_CONTACT_EMAIL` to the address that should receive website contact messages. These are separate addresses; the public contact form does not require visitors to sign in. Messages use the visitor's address as `Reply-To` so you can reply directly.
