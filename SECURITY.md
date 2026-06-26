# Security Policy

## Reporting

Report suspected vulnerabilities privately to the repository owner before
opening a public issue.

Do not include scheduler tokens, email addresses, living-person data or complete
delivery logs in public reports.

## Sensitive Runtime Files

The `data` directory can contain:

- scheduler tokens
- subscriber preferences
- email addresses
- delivery status and logs

Web-server access to this directory must be denied. The distributed `.htaccess`
provides protection for Apache, but administrators using NGINX or IIS must apply
equivalent server configuration.

Regenerate the scheduler token if its URL is disclosed.
