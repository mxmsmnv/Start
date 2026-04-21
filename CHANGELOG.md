# Changelog

## 1.1.1

### Added
- Hungarian translation (20 languages total)

## 1.1.0

### Added
- Multilingual UI — all editor labels are now fully translated: *+ Add link*, *Add group*, *Remove*, *Remove group*, *Browse pages*, *Select a page*, *Select icon*, *Columns*, *Search pages…*, *Loading…*
- New translation keys added to all supported languages: `add_link`, `add_group`, `remove`, `remove_group`, `browse_page`, `select_page`, `select_icon`, `columns`, `search_pages`, `loading`
- `window.stI18n` object injected into the editor page — all JS components read labels from it at runtime
- PagePicker modal header, search placeholder, and loading indicator now respect the configured admin language
- 7 new languages: Turkish, Czech, Finnish, Korean, Hindi, Hebrew, Arabic (19 total)
- RTL layout support for Hebrew and Arabic — editor flex directions, indentation, and text alignment automatically mirror when an RTL language is active

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
