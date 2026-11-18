# Get started with uBixCore — quickstart (target UX, draft)

**Status:** Draft v1, 2026-09-02; reality check updated 2026-09-03 after OSS-02/03. This is the page ubixsys-web will publish. It is
written as the finished experience; the "Reality check" table at the bottom maps
each step to the OSS-xx slice that delivers it. Update both as slices land.

uBixCore is a PHP 8.4+ / Svelte 5 framework **and** the tooling around it — CLI,
code review gate, coding-standard sniffs, PHPUnit base classes, migrations
runner, Docker + GitLab CI templates — shipped together so a new project starts
with the whole workflow, not just a library. You keep your own namespace; the
framework lives in `vendor/` and is never committed to your repo.

Requirements: PHP 8.4+, Composer 2, Node 20+, Docker (for images), git.

## 1. Create the project

```bash
composer create-project ubixsys/ubixcore-skeleton acme
cd acme
```

You get a host project — not a copy of the framework:

```
acme/
  composer.json / composer.lock   requires ubixsys/ubixcore
  public/index.php  bin/ubix      thin entry points (yours to edit)
  app/                            one folder per deployable app (empty)
  php/                            your PSR-4 root goes here (empty)
  tests/
  sql/migrations/
  phpcs.xml  phpstan.neon  phpunit.xml   point at the rules shipped in vendor/
  Dockerfile  .gitlab-ci.yml  config/devops/
  docs/standards/                 the house standards, copied so you own them
  .claude/  AGENTS-COORD.template.md   agent-session tooling
  .env.example
  vendor/                         ubixsys/ubixcore lives here — gitignored
```

## 2. Put it in git

```bash
git init -b dev
git add -A && git commit -m "chore: bootstrap from ubixsys/ubixcore-skeleton"
git remote add origin git@gitlab.example.com:acme/acme.git
git push -u origin dev
```

`composer.lock` is committed on purpose: it is the record of which uBixCore
version every image is built from. `vendor/`, `node_modules/`, `.env*` are not.

## 3. Create your first app and namespace

```bash
cp .env.example .env
bin/ubix app:init AcmeApi --namespace 'Acme\\Api'
```

This scaffolds:

```
app/AcmeApi/src/Routes.php          route table (Slim 4)
app/AcmeApi/src/Dependencies.php    PHP-DI container definitions
app/AcmeApi/dev-deploy.yaml …       k8s manifests from the template
php/Acme/Api/Controller/HealthController.php
tests/Acme/Api/Controller/HealthControllerTest.php
```

and adds `"Acme\\": "php/Acme/"` to `composer.json` autoload. `Ubix\` is the
framework's namespace; you never put code there.

## 4. Run it

```bash
bin/ubix app:run AcmeApi            # PHP built-in server on :8080
curl -s localhost:8080/health       # {"status":"ok"}
```

## 5. Add an endpoint

`php/Acme/Api/Controller/HelloController.php`:

```php
<?php

declare(strict_types=1);

namespace Acme\Api\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ubix\Controller\AbstractController;

final class HelloController extends AbstractController
{
    public function hello(Request $request, Response $response): Response
    {
        return $this->json($response, ['hello' => 'world']);
    }
}
```

`app/AcmeApi/src/Routes.php`:

```php
$app->map(['GET'], '/hello', HelloController::class . ':hello');
```

## 6. Run the gate

```bash
bin/ubix code:review        # phpcs + phpstan (level max) + phpunit, same as CI
```

The skeleton installs a pre-push hook that runs this; a red gate blocks the push.

## 7. Secrets: uBixVault, not `.env`

uBixCore reads credentials from **uBixVault**. Set `VAULT_ADDR` (and
`VAULT_TOKEN` locally, or `VAULT_K8S_ROLE` in Kubernetes) and the bootstrap
pulls `MYSQL_*` from the KV secret at `VAULT_DB_KV_PATH` on every start. `.env`
is for local development only and stays out of git. The Kubernetes login goes
to the `kubernetes` auth mount unless `VAULT_AUTH_PATH` names another (e.g.
`jwt`).

An app with no database sets `VAULT_DB_STRATEGY=none`: the `MYSQL_*` read is
skipped and only the app secrets at `VAULT_APP_KV_PATH` / `VAULT_APP_KV_PATHS`
are hydrated. Leave it unset for an app that has a database, so a missing
database secret still fails loudly. (`kv`, the default, and `dynamic` are the
other values; anything else stops startup with an error naming it.) Do the same for CI tokens
and webhooks: Vault + a read-only token in the pipeline.

## 8. Database + migrations

```bash
bin/ubix migrate:diff        # generates a migration from your schema change
bin/ubix migrate:up
bin/ubix migrate:status
```

## 9. Ship it

```bash
docker build --build-arg APP_NAME=AcmeApi -t acme-api .
```

The Dockerfile copies your code plus `composer.lock` and runs
`composer install --no-dev`, which pulls `ubixsys/ubixcore` from Packagist. The
included `.gitlab-ci.yml` does build → lint-and-test → deploy → notify.

## 10. Frontend (optional)

```bash
bin/ubix app:init AcmeJs --js     # SvelteKit app with @ubixsys/ubixcore
cd app/AcmeJs && npm install && npm run dev
```

## 11. Upgrade uBixCore later

```bash
composer update ubixsys/ubixcore
git add composer.lock && git commit -m "chore: ubixcore 0.4.0"
```

One-line diff, one MR. Files the skeleton copied into your repo (entry points,
configs, CI) are yours; the changelog says when an upgrade wants a change there.

## Installation scenario: self-hosted GitLab + k3s + uBixVault

The quickstart above is the developer's first ten minutes. This is the
operator's first afternoon: taking a `create-project` skeleton to a pipeline
that builds, tests, deploys and promotes on the stack uBixCore was built for.
Everything here is what `docs/ci-setup.md` in the skeleton lists, told as a
sequence. Substitute your own hosts where they appear; the shape is the same.

**Assumed stack.** GitLab (self-hosted, with its container and package
registries), a shell runner with Docker buildx and kubeconfigs for the target
clusters, k3s namespaces per tier (`ws-<env>` for APIs, `live-<env>` for web),
uBixVault per tier (`vault.dev…`, `vault.prod…`) with Kubernetes auth.

1. **Create the project from the skeleton** and push it to GitLab. The pipeline
   file is included and every job targets the `shell` runner tag.
2. **Give the build read access to uBixCore.** The runtime image installs
   `ubixsys/ubixcore` from the private group registry. Create a deploy token
   with `read_package_registry` on the framework's group; you will store it in
   Vault in step 4. Add your project to the job-token allowlist on the
   framework project and on the base-image project, so the fallback path and
   the base-image pull work with the job token alone.
3. **Create the CI variables**, all masked and not protected:
   `GITLAB_PROMOTE_TOKEN` (a Maintainer project access token with `api` +
   `write_repository`, used by the promote jobs) and, after step 4,
   `UBIXVAULT_CI_TOKEN_DEV` / `UBIXVAULT_CI_TOKEN_PROD`.
4. **Seed uBixVault** with `bin/vault-ci-setup.sh <env> <project>`, admin token
   in the environment, once per Vault. It creates the read-only policy, writes
   `secret/<project>/{test-db,composer,discord}`, and prints the CI token for
   step 3. The pods' database credentials live separately at
   `secret/<app>/<env>/db`, read at startup through `VAULT_ADDR` +
   `VAULT_K8S_ROLE`; bind that role to your namespace and ServiceAccount.
5. **Prepare the namespaces:** `regcred` for the registry, the wildcard TLS
   label, and the non-secret runtime config as `env` on each Deployment
   (`MEMCACHE_SERVERS`, database host/port/name, `LOGGER_PATH`, Latte paths,
   log level). The image ships no `.env`; that is deliberate.
6. **Push to `dev`** and read the first failure, if any, against the list at
   the end of `docs/ci-setup.md`. Each one maps to a step above.

What this looked like in practice, with every wrong turn, is in
[`process-log.md`](process-log.md) under 2026-09-03.

---

## Reality check (2026-09-02)

| Step | Exists today? | Delivered by |
|---|---|---|
| 1 `create-project` skeleton | **Yes** — `ubixsys/ubixcore-skeleton` v0.1.0 published from the tag pipeline; proven from a clean `create-project` against GitLab (OSS-09) | — |
| 2 git shape | Yes — lock committed, images `composer install` from it (OSS-06) | — |
| 3 `app:init` + own namespace | Partly — own namespace works end to end (`App\` in the skeleton, commands discovered); no scaffolder yet, copy `app/HelloApi` | OSS-04 for Sowing.me; scaffolder later |
| 4 `app:run` | Yes — resolves `app/` through `ProjectRootService` (OSS-02) | — |
| 5 `AbstractController` in `Ubix\` | Yes | — |
| 6 `code:review` gate + pre-push hook | Yes — `vendor/bin/*` via `ProjectRootService` (OSS-02); `Ubix` phpcs standard, phpstan baseline and PHPUnit base classes ship in the package (OSS-05) | — |
| 7 secrets via uBixVault | Yes — `Ubix\Bootstrap\vault.php`, on when `VAULT_ADDR` is set | — |
| 8 migrations | Yes — `sql/` via `ProjectRootService` (OSS-02) | — |
| 9 Docker + CI templates | Yes for this repo; need to become skeleton templates | OSS-07 |
| 10 `@ubixsys/ubixcore` on npm | No — `js/Ubix` is named `ubix` | OSS-08 |
| 11 upgrade via Composer | Yes — `composer update ubixsys/ubixcore` against the GitLab registry / vcs | — |
