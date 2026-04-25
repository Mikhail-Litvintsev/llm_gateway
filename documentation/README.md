# Documentation

This directory contains guides for integrators, operators and contributors working with the LLM Gateway.

## For integrators

- [Client integration guide](client_integration_guide.md) — end-to-end reference for sending requests, handling responses, webhooks, rate limits and errors.

## For operators (on-call)

- [Operational runbook](operational_runbook.md) — diagnosis, recovery, routine procedures.
- [Microservices setup guide](microservices_setup_guide.md) — deployment, compose topology, environment variables.
- [Commands](commands.md) — custom Artisan commands and their use cases.

## For contributors

- [Internal logic](internal_logic.md) — architecture, component diagrams, data flow.
- [Raw response specification](raw_response_specification.md) — shape of logged Anthropic raw responses, retention policy, PII handling.
- [Architecture decisions](decisions.md) — ADR-001…011 recording non-obvious design choices.

## Benchmarks

Benchmarks live under [`../benchmarks/`](../benchmarks/readme.md). Performance numbers are published in the root [README.md](../README.md).

Project overview: [../CLAUDE.md](../CLAUDE.md).
