# EconomyGUI

**GUI-based shop & sell plugin** for legacy PocketMine-MP / Genisys servers (MCPE 0.14.3/0.15.x). A double-chest interface for buying and selling items with real money, no signs or commands needed for players.

---

## Features

- 🛒 **Shop GUI** — 16 default categories, browse & buy in x1/x10/x64
- 💰 **Sell Chest** — drop items in, close the chest, get paid automatically
- ✋ **Sell Hand / Sell All** — instant sell without opening any GUI
- ⏪ **Sell Undo** — reverse your last sell within a time limit
- 🧟 **SmartSpawner Integration** — buy real, correctly-tagged spawners
- ✨ **AzCustomEnchant Integration** — buy real enchant books
- 🔒 **Anti-Dupe** — menu item tagging + sweep on every interaction
- 🔊 **Sounds & Logging** — configurable sounds, full transaction log
- 🛠️ **In-Game Management** — add/remove items & categories without touching files

---

## Installation

1. Drop the `EconomyGUI/` folder into `plugins/`
2. Install **EconomyAPI** (required — all money operations depend on it)
3. Restart server
4. Edit `config.yml`, `shop.yml`, `sell.yml` to taste
5. Use `/shopreload` to apply changes

> Requires **PocketMine API 2.0.0** (legacy) and **PHP 5.6+**.
> SmartSpawner and AzCustomEnchant are optional soft-dependencies.

---

## Quick Start

**Players:**
1. Run `/shop` → pick a category → pick an item → choose x1/x10/x64
2. Run `/sell` → drop items in the chest → close it to get paid
3. Or skip the GUI: `/sell hand` or `/sell all`
4. Made a mistake? `/sell undo` within the time limit

**Admins:**
```
/shopgui-add 264:0 500 1 general 150   # Add Diamond: buy $500, sell $150
/shopgui-addcat tools 278 §e Tools     # Create a "Tools" category
/shopgui-listcat                        # See all shop categories
/shopreload                             # Reload configs
```

---

## Commands

| Command | Permission | Description |
|---|---|---|
| `/shop` | `economygui.shop` | Open the Shop GUI |
| `/sell` | `economygui.sell` | Open the Sell Chest GUI |
| `/sell hand` | `economygui.sell.hand` | Sell the item in your hand |
| `/sell all` | `economygui.sell.all` | Sell all sellable items in inventory |
| `/sell undo` | `economygui.sell.undo` | Undo your last sell |
| `/shopbalance` | `economygui.shop` | Check your money balance |
| `/shopreload` | `economygui.reload` | Reload all config files |
| `/shopgui-add <id:meta> <price> <amount> [category] [sellprice]` | `economygui.admin` | Add a shop item |
| `/shopgui-remove <id:meta> [category]` | `economygui.admin` | Remove a shop item |
| `/shopgui-addcat <key> <iconId> <name>` | `economygui.admin` | Create a shop category |
| `/shopgui-removecat <key>` | `economygui.admin` | Delete a shop category |
| `/shopgui-listcat` | `economygui.admin` | List shop categories |
| `/sellgui-add / -remove / -addcat / -removecat / -listcat` | `economygui.admin` | Same operations for the sell list |

---

## Permissions

| Permission | Default | Purpose |
|---|---|---|
| `economygui.shop` | true | Access the shop GUI |
| `economygui.sell` | true | Access the sell chest & `/sell` |
| `economygui.sell.hand` | true | Use `/sell hand` |
| `economygui.sell.all` | true | Use `/sell all` |
| `economygui.sell.undo` | true | Use `/sell undo` |
| `economygui.reload` | op | Reload configs |
| `economygui.admin` | op | Manage items & categories |
| `economygui.free` | false | Buy items for free (staff/testing) |

---

## Default Shop Categories (16 total)

| Category | Items |
|---|---|
| Building Blocks | 40+ |
| Wood & Nature | 25+ |
| Stairs & Slabs | 20+ |
| Doors & Fences | 20+ |
| Ores & Minerals | 17 |
| Redstone & Mechanics | 24 |
| Utility & Decor | 23 |
| Flowers & Plants | 40+ |
| Ores & Resources | 13 |
| Food & Drinks | 33 |
| Farming & Seeds | 11 |
| Mob Drops | 14 |
| Tools | 20 |
| Combat & Armor | 28 |
| Miscellaneous | 20 |
| Spawnerz | 44 (11 mobs × 4 tiers) |
| Custom Enchants | 8 |

---

## GUI Overview

Tap `/shop` to open a **54-slot double-chest GUI**:

| Slot / Screen | Action |
|---|---|
| Categories screen | Browse all shop categories |
| Items screen | Browse items inside a category |
| Buy screen | Slots 11 / 13 / 15 → buy x1 / x10 / x64 |
| Slot 45 | Back button |
| Slot 48 / 53 | Previous / Next page |

Sell Chest works the same way in reverse: drop items in, close to sell.

---

## Anti-Dupe System

- **NBT marker** — every GUI item is tagged `economygui_menu_item = 1`
- **Transaction cancellation** — clicks inside the GUI are never real inventory moves
- **Multi-event sweep** — tagged items are stripped on transaction, close, hotbar switch, drop, interact, and quit
- **Real item display** — items look exactly like what you'll receive, safely

---

## Economy

- **EconomyAPI** required for all buy/sell operations
- **SmartSpawner** optional — enables the "Spawnerz" category with real spawner items
- **AzCustomEnchant** optional — enables the "Custom Enchants" category with real enchant books
- Without EconomyAPI: the plugin loads, but all money functions are disabled

---

## Key Config Settings

```yaml
shop-title: "§l§6» §r§eShop §l§6«"
sell-title: "§l§2» §r§aSell Chest §l§2«"
currency-symbol: "$"
purchase-cooldown: 0        # Seconds between purchases (0 = disabled)
log-transactions: true
undo-time-limit: 300        # Seconds before undo expires

sounds:
  enabled: true
  on-purchase: "random.orb"
  on-sell: "random.orb"
  on-error: "mob.villager.no"

buy-slots:
  - slot: 11
    amount: 1
  - slot: 13
    amount: 10
  - slot: 15
    amount: 64
```

---

## Known Limitations

- On legacy MCPE 0.14.3/0.15.x clients, client-side prediction can briefly flash a tagged item in the hotbar before removal — expected and harmless
- Spawnerz / Custom Enchants categories require their respective plugins installed
- EconomyAPI required for all money features

---

## Documentation

📖 Full docs (player guide + developer guide, EN/AR): **[docs.html](docs.html)**

## License

MIT — Author: **Aniss**
