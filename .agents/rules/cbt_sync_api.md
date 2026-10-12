# CBT Offline Sync API Rule

When updating any code in this application that consumes or relies on synchronization data from the remote/local API (such as `api/sync_pull.php` or `api/sync_push.php`), always make the corresponding updates to the API provider endpoint:

- **Path**: `C:\xampp\htdocs\eduportal\api\cbt_sync_api.php`

### Guidelines:
1. Ensure `cbt_sync_api.php` provides any newly required data structures, fields, filters, or endpoints needed by the offline CBT client.
2. Maintain backward compatibility and support for PHP 5.6+ in both `cbt_sync_api.php` and offline scripts.
3. Keep the installer build files (`installer/build/www/api/` and `installer/build/www/admin/`) aligned with `admin/` and `api/`.
