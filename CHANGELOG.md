# Changelog

## 1.0.0

### Added
- Standalone Process page under Setup → Start with list and icon grid view modes
- View mode (list / icon) persisted in `localStorage`
- Visual drag-and-drop link editor at `/setup/start/edit/`
- Groups / sections with drag reorder via Sortable.js
- Column count slider (2–6) with live preview panel
- External link flag (`ext` checkbox, opens in new tab)
- Font Awesome 6 integration — 1887 icons (solid + brands + regular)
- Icon picker popup with real-time search
- Automatic `fab`/`fas` detection via `brands.txt`
- PagePicker modal — browse full page tree from the URL field
- `?action=modules` AJAX endpoint — Example button loads installed Process modules with icons from `getModuleInfo()`
- `?action=pages` AJAX endpoint for PagePicker
- Admin home widget via `ProcessHome::execute` hook
- `start-dashboard` permission for non-admin access control
- Footer *Edit Links* link on the dashboard
- Bottom action bar in editor: Back · Example · Clear all · Save
- Mobile-responsive editor layout
- Responsive CSS grid (collapses on mobile)
