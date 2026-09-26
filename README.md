# Erpy for Acumatica

An **[Erpy](https://justinholt.com/plugins/craft-erpy)** connector for Acumatica Cloud ERP.

Free. Erpy itself is the paid part — it owns the sync engine, the identity map, the field
mapping, the queue, the dead letters and the log. This package's whole job is to translate one
vendor's API into Erpy's canonical documents.

## Installing

```sh
composer require justinholtweb/craft-erpy-acumatica
php craft plugin/install erpy-acumatica
```

Then add a connection under **Erpy → Connections** and pick it from the ERP list.

## What you need to know

### Authentication

A session cookie from `/entity/auth/login`, established once and shared across a whole run. Acumatica counts concurrent sessions against your licence, so an integration that logs in per request will exhaust a small one.

### Endpoint version

Part of the URL. Find it under System → Integration → Web Service Endpoints and use the Default endpoint.

### Field shape

Every value in a contract-based payload is a `{"value": …}` object rather than a scalar. Handled here; worth knowing if you extend it.

## What it syncs

The connection screen shows exactly which entities and directions this connector supports —
it is generated from the connector's own declaration, so it can never advertise a flow it has
not implemented.

## A field is wrong

Correct it on the mapping screen: a rule whose target is a canonical field (`sku`, `unitPrice`,
`customerCode`) overrides what the connector read, before anything reaches Commerce. No fork,
no wait for a release.

## Documentation

The full documentation for this add-on is at
https://justinholt.com/plugins/craft-erpy/docs/acumatica, and Erpy's own is at
https://justinholt.com/plugins/craft-erpy/docs.

## Requirements

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+, and Erpy 5.0+.

## Support

justin@justinholt.com

## License

The Craft License. See `LICENSE.md`. Erpy for Acumatica is free: no editions and no licence key of its own.
It needs a licensed copy of [Erpy](https://justinholt.com/plugins/craft-erpy), which is the paid part.
