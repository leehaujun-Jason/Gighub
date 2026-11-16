# Local installation details

Use a separate local MariaDB server or a dedicated fresh database on a server you control. Set the host and port explicitly in your MariaDB client and in `config/local.php`. Start by querying `SELECT VERSION(), @@port, CURRENT_USER();` to verify the server. The examples use TCP loopback and do not require a specific port.

Set `database.enabled` to `true` and specify `host`, an integer `port` from 1 to 65535, `name`, `username`, and `password` in the ignored `config/local.php`. Use a database name of 1–64 ASCII letters, digits, or underscores. `gighub_schema_test` is reserved for the guarded CLI validation workflow. GigHub connects only to the configured target and verifies the selected database name. Use `127.0.0.1` for a local TCP connection.

## Initialize the database

In an authorized MariaDB administrator session, create an empty database and a dedicated runtime account. Choose your own names and replace the password placeholder privately before executing the example. Do not save the expanded statement in the repository or expose it in command-line arguments.

```sql
CREATE DATABASE gighub_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gighub_runtime'@'127.0.0.1' IDENTIFIED BY '<unique-local-password>';
USE gighub_local;
```

Import [schema.sql](../database/schema.sql) into that empty database using your MariaDB client. DDL implicitly commits. If import stops partway, inspect the error and the partial schema before retrying. The canonical schema already includes migrations 001–013. Apply individual migrations only to an existing, verified older baseline after creating a backup.

Review the [table-level grant template](../database/application-grants.example.sql), replacing the example database and account if needed. Run it as the setup administrator. It is a source-derived baseline for the implemented workflows and still needs end-to-end privilege testing. It grants no global or database-wide privileges, DDL, or grant option.

Verify the initialized schema as administrator:

```sql
SELECT COUNT(*) AS base_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'gighub_local' AND TABLE_TYPE = 'BASE TABLE';
SHOW TRIGGERS FROM gighub_local;
SHOW GRANTS FOR 'gighub_runtime'@'127.0.0.1';
```

Expect 32 base tables and the two wallet-ledger immutability triggers. Connect separately as the runtime account and verify `SELECT DATABASE(), CURRENT_USER(), @@port;` and `SELECT COUNT(*) FROM skills;`. Seed skills only as the setup operator using the [controlled catalogue procedure](skills-catalogue-plan.md).

## Provision the first Admin

Public registration accepts Client and Freelancer roles. Use a trusted local administrator session for the one-time Admin bootstrap. Choose a new email you control, a display name, and a unique password that satisfies the registration policy. Generate its Argon2id hash locally with PHP's `password_hash($password, PASSWORD_ARGON2ID)`. Supply the password privately to your local PHP process, for example through standard input from a trusted password prompt. Keep the password and hash out of source, shell history, screenshots, and shared logs.

Using parameter binding in your local database client, insert a **new** account with the following statement. Bind the chosen email, generated hash, and display name in that order. A duplicate email should fail. Do not promote an existing account as a shortcut.

```sql
INSERT INTO users (email, password_hash, display_name, role, status, email_verified_at, onboarding_pending)
VALUES (?, ?, ?, 'admin', 'active', UTC_TIMESTAMP(), 0);
```

The verified timestamp marks the operator-provisioned account as verified. Admins do not need a Client or Freelancer profile row. Sign in through the usual Login page and confirm the Admin workspace is available. Verify that a normal Client or Freelancer account cannot open it. This procedure has been checked against the schema and authentication code. The end-to-end bootstrap has not been run on a disposable installation.

## Configure private storage and verify

Create separate writable directories outside both the checkout and Apache document root. Set `avatar_storage`, `chat_attachment_storage`, and `marketplace_document_storage` in the ignored local configuration. Use absolute canonical paths without a trailing separator. Grant access to the Apache runtime identity and authorized maintainers. Do not configure an Apache alias to those directories.

Regular Chat and investigation chat share the configured chat storage. Job requirement documents and proposal documents share marketplace storage. Deliverable links are stored in the database. The schema's `uploaded_files` table is not used by the current deliverable submission path.

Start Apache, open the site's public entry point, and verify public browsing, Client and Freelancer registration and verification, Admin login, and authorized upload/download for each storage type. Verify with disposable local accounts. The health endpoint alone does not prove database access.
