# Security policy

## Reporting a vulnerability

Email **security@vizra.ai**. Please do not open a public issue for anything
that could be exploited before it is fixed.

Include whatever you have — the version, what you did, what happened, and a
proof of concept if you have one. You will get an acknowledgement within three
working days, and an assessment with a fix or a timeline within ten.

You are welcome to disclose publicly once a fix has shipped. If you would like
credit in the release notes, say so and name the handle you want used.

## What is in scope

This repository — the `vizra/evals` package and everything it installs into a
host application. Of particular interest:

- Anything that lets eval data cross between projects or teams when reported
  to Vizra Cloud.
- Anything that causes credentials to be written to logs, to disk or over the
  wire. The package is designed never to see your model API keys; a path where
  it does is a bug worth reporting.
- Anything in `evals:runner` that would let a queued run execute something the
  host application did not ask for.

The hosted service at vizra.ai is a separate report — same address.

## What is not

Findings that require an attacker to already control the host application, its
database or its configuration files. The package runs inside your app with your
privileges by design.
