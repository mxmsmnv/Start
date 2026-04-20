# Start — ProcessWire Dashboard Module

Personal quick-access dashboard for the ProcessWire admin. Replaces the default home screen with a configurable grid of links to the pages and modules you use most.

## Features

- **Two view modes** — list and icon grid, preference saved in `localStorage`
- **Visual link editor** at `/setup/start/edit/` — drag-and-drop groups and items, no page reloads
- **Font Awesome 6** icons — 1887 icons with a searchable popup picker; brand icons (`fab`) detected automatically
- **PagePicker** — browse the full page tree directly from the URL field
- **Example button** — auto-populates with your installed Process modules and their icons from `getModuleInfo()`
- **Widget on admin home** — compact link list shown on the default ProcessWire dashboard
- **Access control** — `start-dashboard` permission, assignable to any role via Access → Roles
- Mobile-friendly editor layout

## Requirements

- ProcessWire 3.0.0 or newer
- PHP 8.0+

## Installation

1. Copy the `Start/` folder into `/site/modules/`
2. Go to **Modules → Refresh**
3. Install **Start**
4. Navigate to **Setup → Start** or go to `/setup/start/edit/` to add your first links

The module creates a `start-dashboard` permission on install. Assign it to any role under **Access → Roles** to grant non-admin users access.

## File structure

```
Start/
├── Start.module.php
└── fontawesome/
    ├── brands.txt          # 492 brand icon names for fab/fas detection
    ├── css/
    │   └── all.min.css     # Font Awesome 6 (solid + brands + regular)
    └── webfonts/
        ├── fa-solid-900.woff2
        ├── fa-brands-400.woff2
        ├── fa-regular-400.woff2
        └── fa-v4compatibility.woff2
```

## Usage

### Adding links

Go to **Setup → Start** and click *Edit Links* in the footer, or go directly to `/setup/start/edit/`.

- **Add group** — creates a named section (e.g. *Content*, *Modules*)
- **Add link** — label, URL, icon, optional *ext* flag for external links
- **Browse** (folder button) — opens PagePicker to select any page from the tree
- **Icon** button — opens the icon popup with search across 1887 Font Awesome icons
- Drag the `≡` handle to reorder groups or links

### Example button

Click **Example** to auto-fill the editor with all installed Process modules. Each module gets its icon from `getModuleInfo()['icon']` and its correct admin URL.

### Icon names

Use standard Font Awesome 6 names with or without the `fa-` prefix — both `github` and `fa-github` work. Brand icons (`fab`) are detected automatically via `brands.txt`.

## Permissions

| Permission | Description |
|---|---|
| `start-dashboard` | View the Start dashboard and use the link editor |

Superusers always have access regardless of permissions.

## Author

**Maxim Semenov** — [maxim@smnv.org](mailto:maxim@smnv.org)  
[https://github.com/mxmsmnv/Start](https://github.com/mxmsmnv/Start)

## License

MIT
