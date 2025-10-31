# Mail Template Update Workflow

## Overview

This plugin does **NOT** automatically update mail templates on plugin updates to preserve user customizations made in the UI.

## Philosophy

- ✅ Templates are created once on initial install (via migrations)
- ✅ Users can customize templates in Admin UI
- ✅ Customizations are preserved during plugin updates
- ✅ To deploy new template wording: use uninstall/reinstall workflow

## Workflow: Updating Template Wording

### For Integration Environment (No DB Access)

**When you need to update mail template wording:**

1. **Update template content in migration files**
   - Edit the text in `src/Migration/Migration*.php`
   - Keep templates as heredoc strings (plain text, not files)

2. **Bump plugin version**
   ```json
   // composer.json
   "version": "0.1.3"  // Increment from 0.1.2
   ```

3. **Test locally**
   ```bash
   cd shopware-local
   make cc-update
   ```

4. **Build release**
   ```bash
   cd fb-sw-click-collect
   make build
   # Creates build/FbClickCollect.zip
   ```

5. **Deploy to integration (via UI)**
   - Login to Shopware Admin
   - Navigate to: Extensions → My Extensions
   - Click: "..." → "Uninstall" on FbClickCollect
   - **⚠️ IMPORTANT**: Check "Delete all app data" checkbox
   - Click "Uninstall"
   
6. **Reinstall plugin**
   - Click "Upload extension"
   - Select new `FbClickCollect.zip`
   - Click "Install" → "Activate"
   - ✅ Templates created with new wording

7. **Reconfigure system settings**
   - Navigate to: Settings → System → Plugins → FbClickCollect
   - Re-enter store configuration:
     - Store email
     - Store name
     - Store address
     - Opening hours
   - Save settings

### What Gets Removed on Uninstall

When you check "Delete all app data":

**Removed:**
- ❌ Mail templates (all 4 types)
- ❌ Flow builder flows
- ❌ System configuration (store settings)

**Preserved:**
- ✅ Order history (all orders remain intact)
- ✅ Custom field data in orders (JSON values persist)
- ✅ Shipping method (Click & Collect)
- ✅ Payment method settings

### Alternative: Manual Template Edit

If you only need minor wording changes:

1. Update plugin with new code (won't affect templates)
2. Admin manually edits templates in UI:
   - Settings → Shop → Mail templates
   - Find and edit each C&C template
   - Update wording manually
3. Test emails

## For Development

### Testing Template Updates

**Clean install (fresh templates):**
```bash
cd shopware-local
make project-fresh  # Full reset
```

**Update existing install (preserves customizations):**
```bash
cd shopware-local
make cc-update  # Templates NOT updated
```

**Simulate integration uninstall/reinstall:**
```bash
cd shopware-local
# Via UI: Uninstall with "Delete all app data"
# OR via CLI:
docker compose exec shop bin/console plugin:uninstall FbClickCollect --skip-user-data
docker compose exec shop bin/console plugin:install FbClickCollect --activate
```

### Why NOT Auto-Update Templates?

**We considered auto-updating templates in `postUpdate()` but chose not to because:**

- ❌ Would overwrite user customizations made in UI
- ❌ Users would lose their changes on every plugin update
- ❌ No way to preserve custom wording/branding

**Current approach:**
- ✅ Users can customize templates freely
- ✅ Customizations are preserved
- ✅ Explicit uninstall/reinstall when you need fresh templates
- ✅ Clear separation: code vs. user data

## Future Improvements

If auto-updates become necessary:

**Option 1:** Conditional updates
```php
// Only update templates that haven't been modified
if ($template->updatedAt === $template->createdAt) {
    $this->updateTemplate($template);
}
```

**Option 2:** Version tracking
```php
// Track template version in HTML comment
<!-- Template Version: 0.1.3 -->
```

**Option 3:** Separate "custom" flag
```php
// Let users mark templates as "customized"
if (!$template->isCustomized()) {
    $this->updateTemplate($template);
}
```

For now, the uninstall/reinstall workflow is the cleanest approach.
