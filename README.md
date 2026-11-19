# GigHub

GigHub is a freelance marketplace under active development as a Software Engineering Final Year Project. It demonstrates the workflow from posting a job and comparing proposals to reviewing deliverables, closing a contract, and handling disputes.

## Features and roles

| Role | Workflows |
| --- | --- |
| Client | Manage a profile, publish jobs with requirement documents, review bids and proposal attachments, award a bid, fund simulated escrow, review milestones and final work, and leave feedback. |
| Freelancer | Manage a public profile and skills, browse and filter jobs, see skill matches, submit and manage bids, submit deliverables with labelled links, and review completed contracts. |
| Admin | Inspect dispute evidence, contact each party in a separate private investigation thread, and make a dispute decision. |

GigHub also includes email verification, password reset, role-specific onboarding, bid-scoped chat with private attachments, notifications, mutual cancellation, and two-way reviews. Investigation chat and ordinary bid chat use separate authorization and persistence.

## Stack and architecture

- PHP 8.2, PDO, MariaDB 10.4, and Apache. The development setup uses Windows and XAMPP for Apache/PHP with a separate MariaDB instance.
- Server-rendered PHP views, CSS, and vanilla JavaScript. Composer manages PHPMailer.
- `public/index.php` handles requests, and `bootstrap/app.php` wires routes and dependencies. Controllers coordinate requests, services enforce workflow rules and transactions, and repositories own SQL. Views and browser assets render the UI.

| Directory | Contents |
| --- | --- |
| `app/` | Controllers, services, repositories, authentication helpers, mail, and views |
| `bootstrap/` | Application composition and routes |
| `config/` | Defaults and an example local override |
| `database/` | Canonical schema and versioned migrations |
| `public/` | HTTP entry point and static assets |
| `docs/` | Design and module notes |
| `tests/` | Offline checks and separately guarded integration harnesses |

## Local setup

1. Install PHP 8.2, Composer, Apache, and a dedicated MariaDB instance. Enable PHP `pdo_mysql`, `mbstring`, and `openssl`. The photo and chat upload paths also use `fileinfo`, GD with JPEG/PNG/WebP support, and Zip for Office document validation. Verify extensions in **Apache's PHP runtime** as well as in the CLI.
2. Run `composer install` from the repository root. Copy `config/local.example.php` to `config/local.php`. Use your own endpoint and private application credentials. Set `database.enabled` to `true` once the database is ready. The original developer setup uses loopback port 3309. Never point this project at the damaged XAMPP MySQL instance.
3. Create an empty application database using a local database administrator, then import `database/schema.sql` into it. The current working-tree schema includes 33 tables through Migration 014. A fresh import already contains those changes. For an existing installation, inspect its baseline and apply only the missing migrations in order after a verified backup. Some migrations are additive but cannot be replayed. Keep real dumps outside the repository and webroot.
4. Serve the `public/` directory with Apache. On the original XAMPP layout, open [GigHub locally](http://localhost/GigHub/public/index.php). If using the parent document root, verify Apache honors the repository's access controls and denies direct requests to `config/`, `database/`, and other non-public directories. Set `verification.base_url` to the actual public base URL.
5. Set the three private storage directories in the ignored local override. Follow the [storage setup instructions](docs/local-installation.md#configure-private-storage-and-verify). Existing installations with the original paths can keep their current files. For five-file chat uploads, size Apache/PHP request limits above 50 MB including multipart overhead and allow at least five uploads.

### Database permissions

Follow the [database setup and verification instructions](docs/local-installation.md#initialize-the-database) and review the [table-level grant template](database/application-grants.example.sql). Use a separate administrator for setup. The grant template is based on the application SQL and still needs a fresh-install runtime test.

### Safe demo accounts

Use public registration for disposable Client and Freelancer accounts, verify their emails, and complete onboarding. For real verification and password-reset emails, configure SMTP privately in the ignored override. Mail is disabled in the example. Follow the [local Admin provisioning procedure](docs/local-installation.md#provision-the-first-admin) for the first administrator, and the [controlled skills catalogue procedure](docs/skills-catalogue-plan.md) for skills.

## Screenshots

Screenshots can be added using synthetic accounts and sample content.

- Public marketplace and job details
- Client job management and bid review
- Freelancer profile and milestone workspace
- Admin dispute dossier with synthetic evidence

## Academic scope

GigHub is an academic prototype. Wallet balances, escrow, and settlement are simulated inside the application. There is no payment gateway or real transfer of money. Isolated checks covered schema import, scoped grants, Admin login, and Client and Freelancer registration up to pending verification. Full fresh-install workflow verification is still incomplete.
