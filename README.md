# WordPress Health Check

Simple WordPress health check endpoint

#### Installation

```bash
composer require frosty-media/wp-health-check
```

#### Additional requirements
Composer autoloading should be including in application's bootstrap, in this case the `wp-config.php` file.

If running Nginx, add the following to your sites conf rules:

```apacheconf
rewrite ^/meta/health-check/?$ /vendor/frosty-media/wp-health-check/src/check.php last;
```

If running Apache, add the following to your `.htaccess` file (before any WordPress rules):

```apacheconf
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^meta/health-check/?$ /vendor/frosty-media/wp-health-check/src/check.php [L]
</IfModule>
```

If you would like to include the MU Plugin (which creates the internal REST API route `wp-json/health/check?`) 
Update your `scripts.post-update-cmd`, or run 
`composer config scripts.post-update-cmd.0 "FrostyMedia\\WpHealthCheck\\Composer\\Scripts::postUpdate"`

```json
{
  "scripts": {
    "post-update-cmd": [
      "FrostyMedia\\WpHealthCheck\\Composer\\Scripts::postUpdate"
    ]
  }
}
```

