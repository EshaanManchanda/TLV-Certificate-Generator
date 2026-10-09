# Security Policy

[← Back to README](README.md)

## Reporting a vulnerability

Email **techlovev@gmail.com** with the subject "Security: Certificate Generator". Please include:

- the plugin version (Plugins screen or the `Version:` header in `certificate-generator.php`)
- the steps to reproduce, and the impact
- whether the issue has been disclosed anywhere else

Please don't open a public issue for a vulnerability.

You'll get a reply within 3 working days. Fixes are released as soon as they're ready, and within 90 days of the report at the latest. After that you're free to publish. We credit reporters in the changelog unless you'd rather not be named.

## Supported versions

Only the latest release receives security fixes. Update from the Plugins screen, or download the latest ZIP from https://techlovev.in.

## Verifying downloads

Each release ZIP is published with a SHA-256 checksum. Check the file before you install it:

```
sha256sum -c certificate-generator-<version>.zip.sha256
```

On Windows: `Get-FileHash certificate-generator-<version>.zip -Algorithm SHA256`.
