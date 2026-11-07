# MAURO — guide for the website owner

This guide explains everything you need to run your library from the **admin dashboard**. You do not need to write
code or touch the database. Button names are given in English and Arabic (as they appear on screen).

---

## 1. Sign in

1. Open **`https://<your-domain>/admin/login`** (on this computer: `http://127.0.0.1:8000/admin/login`).
2. Enter your **username** and **password**. The eye icon shows or hides what you type.
3. If you were given a **temporary** password, MAURO asks you to choose your own right away. Pick something long that
   only you know — a short sentence of several words works well (at least 12 characters).

Good to know:
- After 5 wrong attempts the sign-in pauses for 15 minutes. This protects your account.
- For safety you are signed out automatically after 8 hours.
- To sign out, use **Sign out** (تسجيل الخروج) in the top bar.

**Forgot your password?** Your technical contact runs one command on the server
(`php bin/console admin:reset-password <your-username>`) and gives you a new temporary password. Nobody — not even
them — can read your current password.

## 2. Change your password

Dashboard → your initial in the top bar → **Change password**, or open `/admin/password`.
Type the current password, then the new one twice, and save.

## 3. Add an author

**Authors** (المؤلفون) → **Add author** (إضافة مؤلف) → name (as it should appear on the site) → **Save** (حفظ).
The "slug" (web address) fills itself in if you leave it empty.

## 4. Add a category

**Categories** (التصنيفات) → **Add category** (إضافة تصنيف) → Arabic name, English name, optional descriptions →
status **Active** → **Save**. A category can sit under another one (parent).

## 5. Create a book

**Books** (الكتب) → **Add book** (إضافة كتاب), then fill in:

| Field | Tip |
|---|---|
| Title / English title | The English title appears under the Arabic one |
| Language, Visibility | *Public* = everyone can read; *Members* = signed-in readers only |
| Publisher, year, edition, ISBN | As printed in the book |
| Description / English description | Blank lines create paragraphs |
| **Authors** | Tick them **in the order they should be credited** — numbers 1, 2, … appear. To change the order, untick and tick again |
| Categories | At least one |
| **Rights** | Status (owned / licensed / public domain), holder, and **source** (e.g. "Author's written permission, 2026-11-06"). Publishing is blocked until this is filled |
| Featured | Shows the book on the home page |

Click **Save** (حفظ). The book is saved as a **draft** — not visible to the public yet.

## 6. Upload the cover

On the book page, section **Cover** (الغلاف): choose a JPEG, PNG or WebP image (up to 2 MB, portrait 2:3 looks best)
→ **Upload** (رفع). You can replace or remove it later.

## 7. Upload the PDF and process it

1. Section **PDF file**: choose the PDF (up to 10 MB) → **Upload** (رفع).
2. A version **v1** appears as **Waiting for processing** (بانتظار المعالجة).
3. Books up to 60 pages: click **Process now** (معالجة الآن) and wait a few seconds.
   Longer books are processed automatically by the server within a few minutes — just come back later.
4. When the badge says **Ready** (جاهز) with the right page count, the book can be published.

MAURO never shows the PDF itself to readers: every page is turned into a protected image with a personal watermark.

## 8. Publish

The **Publish** (نشر) button stays greyed out, with a list of what is missing, until the PDF is Ready, an author and a
category are chosen, and the rights are recorded. When it is enabled, click it. Then **View public page**
(عرض الصفحة العامة) to see the book as readers do.

Other actions: **Unpublish** (إلغاء النشر) hides it again; **Archive** (أرشفة) puts it away; **Restore** brings it back
as a draft.

## 9. Check the public website

Your book appears on the home page (newest books, and featured if ticked), in the library, in its category, and in
search (by title or author). Open it and press **Read now** (اقرأ الآن) to read it page by page.

## 10. If an upload fails

| Message | What to do |
|---|---|
| "not a valid PDF" / "could not be read; it may be damaged" | Export the PDF again from Word/your publishing tool and re-upload |
| "password-protected" | Save a copy without a password and upload that |
| "exceeds the maximum size" | Reduce the file size (e.g. "Reduce file size" / "Optimize PDF") |
| "Too many pages" | Ask your technical contact to raise the limit |
| "Not enough storage space on the server" | Ask your technical contact to free or add space |
| Version shows **Failed** with **Retry** (إعادة المحاولة) | Click **Retry**; if it fails again, contact your technical contact with the message shown |
| Stays "Waiting for processing" for a long time (books over 60 pages) | The automatic processor may be stopped — contact your technical contact |

A failed upload never publishes anything and never damages a book that is already online. When you replace a PDF,
readers keep the old version until the new one is completely Ready.

## 11. Back up the library

Backups run automatically every night on the server (database + original PDFs + covers). Ask your technical contact
to copy them to a second place regularly, and to test a restore once a month. Manual backup command for them:
`php bin/console backup:run`.

## 12. Other screens

**Users** — suspend or reactivate reader accounts. **Reviews** — hide inappropriate reviews. **Analytics** — reading
numbers. **Settings** — books per page, featured book, contact e-mail. **Activity log** — who did what and when,
including every sign-in.
