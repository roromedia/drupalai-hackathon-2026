# Drupal AI Hackathon 2026

This project is based on **Drupal CMS**, a fast-moving open source product that enables site builders to create Drupal
sites with smart defaults using only their browser.

This repository serves as the official starting point for hackathon participants.

## Table of Contents

* [Participation workflow](#participation-workflow)
* [Getting started](#getting-started)
* [AI provider and model requirements](#ai-provider-and-model-requirements)
* [amazee.io AI provider setup](#amazeeio-ai-provider-setup)
* [Configuration export requirement](#configuration-export-requirement)
* [Drupal Forge setup guide](#drupal-forge-setup-guide)

## Participation workflow

Participants must **fork this repository** into a GitHub account of their choosing.

All development must happen in the forked repository.

When ready for review, participants must open a **pull request back to this repository** so that judges can review and evaluate the submission.

## Getting started

If you want to use DDEV to run Drupal CMS locally, follow these steps:

1. Install DDEV following the documentation at [https://ddev.com/get-started/](https://ddev.com/get-started/)
2. Open a terminal and change to the root directory of this project
3. Run the following commands:

```shell
ddev start
ddev install
ddev launch
```

Drupal CMS has the same system requirements as Drupal core. You may use any supported local setup if you prefer.

See the Drupal User Guide for more information on installation options:
[https://www.drupal.org/docs/user_guide/en/installation-chapter.html](https://www.drupal.org/docs/user_guide/en/installation-chapter.html)

## AI provider and model requirements

This distribution is configured to use an AI provider via the Drupal AI module. Participants **must use the amazee.io provider**
for all AI-powered features in their submission. Non-amazee.ai providers are not permitted unless explicitly approved by hackathon organizers.

Participants may add any Drupal modules they require for their solution.

## amazee.io AI provider setup

To configure the provider:

4. Visit the amazee.io provider configuration form at **Configuration → AI → Provider Settings → amazee.io Authentication**
   (typically `/admin/config/ai/providers/amazeeio`).
5. Enter the **email address used to register for the hackathon** to begin authentication.
6. Check your email for a verification code from amazee.ai, enter it on the form, and submit.
7. Once verified, amazee.ai credentials (LLM key and VectorDB key) will be stored in the **Keys** module at
   `/admin/config/system/keys`. ([drupal.org][1])

## Configuration export requirement

Before opening a pull request, participants must export configuration using the following command:

```shell
ddev drush cex
```

The exported configuration must be committed and included in the pull request. Pull requests without exported configuration
will not be considered complete.

## Drupal Forge setup guide

This section describes how to set up your [Drupal Forge](https://www.drupalforge.org/) project and development environment for the hackathon.

### 1. Setting Up a Drupal Forge Account

Each developer must create an individual **Drupal Forge** account using a valid email address.

After registration, each team member must send the following to the hackathon organization:

- Email address used for registration
- Full name
- Team name / team number

**Note**: analysts do **not** need Drupal Forge access. They will only use the live site URL.

### 2. Forking the Source Repository on GitHub

Steps:

1. Go to the official hackathon source repository.
2. Create a **fork** under your personal GitHub account.
3. **Do not rename** the repository.

**Important**: The forked repository becomes your team’s **single source of truth**. Drupal Forge templates and environments
are disposable — GitHub is not.

### 3. Creating a Project from the Forked Repository

Steps:

1. Log in to **Drupal Forge**.
2. Click **My App** next to the user menu.
3. On the My App page, click the **DevPanel logo** in the top-right corner to open the DevPanel web app.
4. Log in using the **same email** as your Drupal Forge account.
5. You will be redirected to the **Workspaces** page, create one.
6. Access the workspace you just created and click **+ Create project from scratch**.

Fill in the form as follows:

- **Name:** `Team [number] - [team name]`
- **Application Type:** Drupal 11
- **Source Code:** GitHub
- **GitHub Account:** Link your account by logging in
- **Repository:**
  - Use existing repository
  - Select your fork from Step 2
  - Select the `master` branch

You should now be able to deploy the `master` branch.

### 4. Configuring the Development Website

Steps:

1. From the **Application Summary** page, click **Open Application** to access the Dev Environment (browser VS Code).
2. Copy the displayed password — you will need it to access the environment.
3. In browser VS Code open a terminal window.
4. Run the following commands to initialize the Drupal website:

```shell
composer install
drush -y si --existing-config --db-url="${DB_DRIVER}://${DB_USER}:${DB_PASSWORD}@${DB_HOST}:${DB_PORT}/${DB_NAME}"
```
