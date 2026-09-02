# Laravel Nightwatch on Laravel Cloud

Nightwatch is installed in the application, but monitoring remains disabled
until an environment-specific token is configured. Do not commit the token.

## Recommended Cloud integration

1. Create the Rokn application and production environment in Laravel
   Nightwatch, then copy its environment token.
2. In the Laravel Cloud environment dashboard, select **Connect Nightwatch**.
3. Enter the token and enable monitoring for that environment.
4. Confirm these environment values in Cloud:

   ```dotenv
   NIGHTWATCH_ENABLED=true
   NIGHTWATCH_TOKEN=<environment token>
   NIGHTWATCH_REQUEST_SAMPLE_RATE=0.1
   NIGHTWATCH_COMMAND_SAMPLE_RATE=1.0
   NIGHTWATCH_SCHEDULED_TASK_SAMPLE_RATE=1.0
   NIGHTWATCH_EXCEPTION_SAMPLE_RATE=1.0
   LOG_CHANNEL=stack
   LOG_STACK=laravel-cloud-socket,nightwatch
   ```

5. Redeploy, then confirm agent health and incoming traces from the Nightwatch
   dashboard. Keep `NIGHTWATCH_ENABLED=false` in test and local environments.

Laravel Cloud's built-in integration is preferred. If it is unavailable, add
one custom background process to every App compute and Worker cluster:

```bash
php artisan nightwatch:agent
```

The manual process requires the same token and sampling variables above. Do
not run a second manual agent when the built-in integration is enabled.

Official references: [Nightwatch start guide](https://nightwatch.laravel.com/docs/start-guide),
[Laravel Cloud guide](https://nightwatch.laravel.com/docs/guides/cloud), and
[sampling guidance](https://nightwatch.laravel.com/docs/filtering).
