# EconomyGUI v1.0.0

> **Shop & Sell GUI Plugin for PocketMine-MP API 2.0.0**  
> Minecraft Bedrock Edition 0.14.3 / 0.15.x  
> Author: Aniss | License: MIT

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [Dependencies](#dependencies)
3. [Installation](#installation)
4. [Commands](#commands)
5. [Permissions](#permissions)
6. [Configuration (config.yml)](#configuration-configyml)
7. [Shop System (shop.yml)](#shop-system-shopyml)
8. [Sell System (sell.yml)](#sell-system-sellyml)
9. [GUI Navigation](#gui-navigation)
10. [Sell Hand / Sell All / Sell Undo](#sell-hand--sell-all--sell-undo)
11. [SmartSpawner Integration](#smartspawner-integration)
12. [AzCustomEnchant Integration](#azcustomenchant-integration)
13. [Anti-Dupe System](#anti-dupe-system)
14. [Sound Effects](#sound-effects)
15. [Transaction Logging](#transaction-logging)
16. [Admin Commands Reference](#admin-commands-reference)
17. [Configuration Reference](#configuration-reference)
18. [Technical Architecture](#technical-architecture)
19. [Troubleshooting](#troubleshooting)

---

## Overview

EconomyGUI is a full-featured shop and sell plugin for legacy PocketMine-MP servers. It provides:

- **Shop GUI** — Browse categorized items in a double-chest (54-slot) interface with pagination, buy items in quantities of 1x, 10x, or 64x
- **Sell Chest** — Open a chest, drop in items, close it — sellable items are automatically sold and money is added
- **Sell Hand** — `/sell hand` — instantly sell the item you're holding
- **Sell All** — `/sell all` — instantly sell every sellable item in your inventory
- **Sell Undo** — `/sell undo` — reverse your last sell within 5 minutes
- **SmartSpawner Integration** — Buy real spawner items (with NBT type/tier) from a dedicated "Spawnerz" category
- **AzCustomEnchant Integration** — Buy custom enchantment books from a "Custom Enchants" category
- **Anti-Dupe Protection** — Multi-layer NBT marker system that strips menu items from real inventories
- **Full Admin Control** — Add/remove items and categories for both shop and sell via in-game commands
- **Transaction Logging** — Optional log file of all purchases and sales

---

## Dependencies

| Plugin | Required? | Purpose |
|---|---|---|
| **EconomyAPI** | ✅ Required | All money operations (buy, sell, balance) |
| **SmartSpawner** | ⚠️ Optional | Spawnerz category — gives real spawner items with NBT |
| **AzCustomEnchant** | ⚠️ Optional | Custom Enchants category — gives enchant books with NBT |

> If EconomyAPI is not installed, the plugin will load but all economy functions will be disabled with a warning.

---

## Installation

1. Download the plugin folder
2. Place the `EconomyGUI` folder in your server's `plugins/` directory
3. Start/restart the server
4. Three config files are auto-generated:
   - `plugins/EconomyGUI/config.yml` — Main configuration
   - `plugins/EconomyGUI/shop.yml` — Shop items & categories
   - `plugins/EconomyGUI/sell.yml` — Sell items & categories
5. Configure prices and categories to your liking
6. Use `/shopreload` to reload configs without restarting

---

## Commands

### Player Commands

| Command | Description | Permission |
|---|---|---|
| `/shop` | Open the Shop GUI | `economygui.shop` |
| `/sell` | Open the Sell Chest GUI | `economygui.sell` |
| `/sell hand` | Sell the item in your hand | `economygui.sell.hand` |
| `/sell all` | Sell all sellable items in inventory | `economygui.sell.all` |
| `/sell undo` | Undo your last sell (5 min window) | `economygui.sell.undo` |
| `/shopbalance` | Check your money balance | `economygui.shop` |
| `/shopreload` | Reload all config files | `economygui.reload` |

### Admin Commands — Shop Management

| Command | Description |
|---|---|
| `/shopgui-add <id:meta> <price> <amount> [category] [sellprice]` | Add item to shop |
| `/shopgui-remove <id:meta> [category]` | Remove item from shop |
| `/shopgui-addcat <key> <iconId> <displayName>` | Create a new shop category |
| `/shopgui-removecat <key>` | Delete a shop category |
| `/shopgui-listcat` | List all shop categories |

### Admin Commands — Sell Management

| Command | Description |
|---|---|
| `/sellgui-add <id:meta> <price> <amount> [category]` | Add item to sell list |
| `/sellgui-remove <id:meta> [category]` | Remove item from sell list |
| `/sellgui-addcat <key> <iconId> <displayName>` | Create a new sell category |
| `/sellgui-removecat <key>` | Delete a sell category |
| `/sellgui-listcat` | List all sell categories |

---

## Permissions

| Permission | Default | Description |
|---|---|---|
| `economygui.shop` | `true` | Access the shop GUI |
| `economygui.sell` | `true` | Access the sell chest and `/sell` |
| `economygui.sell.hand` | `true` | Use `/sell hand` |
| `economygui.sell.all` | `true` | Use `/sell all` |
| `economygui.sell.undo` | `true` | Use `/sell undo` |
| `economygui.reload` | `op` | Reload configs with `/shopreload` |
| `economygui.admin` | `op` | All admin commands (add/remove items & categories) |
| `economygui.free` | `false` | **Buy items for free** — no money deducted |

> The `economygui.free` permission is deliberately `false` by default. Grant it only to staff/testing accounts.

---

## Configuration (config.yml)

```yaml
# ── Titles ──
shop-title: "§l§6» §r§eShop §l§6«"
sell-title: "§l§2» §r§aSell Chest §l§2«"
confirm-sell-title: "§l§e» §r§6Confirm Sell §l§e«"

# ── Economy ──
currency-symbol: "$"

# ── Defaults ──
default-category: "general"
default-category-icon: 54

# ── Cooldown ──
purchase-cooldown: 0          # Seconds between purchases (0 = disabled)

# ── Logging ──
log-transactions: true

# ── Undo ──
undo-time-limit: 300          # Seconds (default: 5 minutes)

# ── Sounds ──
sounds:
  enabled: true
  on-purchase: "random.orb"
  on-sell: "random.orb"
  on-error: "mob.villager.no"

# ── Buy Buttons Layout ──
buy-slots:
  - slot: 11
    amount: 1
  - slot: 13
    amount: 10
  - slot: 15
    amount: 64

# ── Sell Multipliers (for future use) ──
sell-multipliers:
  - slot: 11
    multiplier: 1
  - slot: 13
    multiplier: 2
  - slot: 15
    multiplier: 5

# ── Back Button ──
back-button-meta: 14          # Wool color (14 = red)
back-button-name: "§r§c« Back"

# ── Messages (all customizable) ──
messages:
  shop-opened: "..."
  purchase-success: "..."
  sell-success: "..."
  # ... (30+ customizable messages)
```

### Message Placeholders

| Placeholder | Description |
|---|---|
| `{amount}` | Item count |
| `{item}` | Item name |
| `{symbol}` | Currency symbol |
| `{price}` | Price (formatted) |
| `{needed}` | Amount still needed |
| `{has}` | Amount player has |
| `{balance}` | Player's balance |
| `{id}` | Item ID |
| `{meta}` | Item meta/damage |
| `{category}` | Category key |
| `{count}` | Number of items returned |

---

## Shop System (shop.yml)

### Category Structure

```yaml
categories:
  block_building:              # Category key (lowercase, no spaces)
    name: §r§eBuilding Blocks   # Display name
    icon: 5                     # Item ID for category icon
    items:
      - id: 1
        meta: 0
        name: §r§eStone
        price: 30               # Price per unit
        amount: 1               # Items given per purchase
        sell: 8                 # (Optional) Sell-back price
        description: "A basic building block"  # (Optional)
```

### Spawner Category (SmartSpawner)

```yaml
categories:
  spawnerz:
    name: §r§bSpawnerz
    icon: 52                    # Monster Spawner block
    spawner_types:              # Sub-categories for each mob
      iron_golem:
        name: §r§bIron Golem Spawners
        icon: 52
        items:
          - id: 52
            meta: 0
            name: §7Basic Iron Golem Spawner
            price: 400000
            amount: 1
            spawner_type: iron_golem   # SmartSpawner mob type
            spawner_tier: basic        # SmartSpawner tier
```

### Custom Enchants Category

```yaml
categories:
  custom_enchants:
    name: §r§dCustom Enchants
    icon: 403                    # Enchanted Book
    items:
      - id: 403
        meta: 0
        name: §r§6Excavator Book
        price: 250000
        amount: 1
        enchant_key: excavator   # AzCustomEnchant key
```

### Default Shop Categories

The plugin ships with **16 pre-configured categories**:

| Category Key | Display Name | Items |
|---|---|---|
| `block_building` | Building Blocks | 40+ blocks |
| `block_wood_nature` | Wood & Nature | 25+ items |
| `block_stairs_slabs` | Stairs & Slabs | 20+ items |
| `block_doors_fences` | Doors & Fences | 20+ items |
| `block_ores_minerals` | Ores & Minerals | 17 items |
| `block_redstone` | Redstone & Mechanics | 24 items |
| `block_utility` | Utility & Decor | 23 items |
| `block_flowers_plants` | Flowers & Plants | 40+ items |
| `item_ores_resources` | Ores & Resources | 13 items |
| `item_food` | Food & Drinks | 33 items |
| `item_farming` | Farming & Seeds | 11 items |
| `item_mob_drops` | Mob Drops | 14 items |
| `item_tools` | Tools | 20 items |
| `item_combat_armor` | Combat & Armor | 28 items |
| `item_misc` | Miscellaneous | 20 items |
| `spawnerz` | Spawnerz | 44 items (11 mobs × 4 tiers) |
| `custom_enchants` | Custom Enchants | 8 items |

---

## Sell System (sell.yml)

Same structure as shop.yml but with different prices (sell prices are typically lower than buy prices).

```yaml
categories:
  block_building:
    name: §r§eBuilding Blocks
    icon: 5
    items:
      - id: 1
        meta: 0
        name: §r§eStone
        price: 8          # Sell price per unit
        amount: 1
```

---

## GUI Navigation

### Shop GUI Flow

```
Categories Screen (slots 0-44)
  ├── Click a category → Items Screen (slots 0-44)
  │     ├── Click an item → Buy Screen
  │     │     ├── Slot 11: Buy 1x
  │     │     ├── Slot 13: Buy 10x
  │     │     ├── Slot 15: Buy 64x
  │     │     └── Slot 45: Back to Items
  │     └── Slot 45: Back to Categories
  │
  └── Spawnerz category → Spawner Types Screen (slots 0-44)
        ├── Click a mob type → Tier Items Screen
        │     ├── Click a tier → Buy Screen
        │     └── Slot 45: Back to Spawner Types
        └── Slot 45: Back to Categories
```

### Navigation Row (Slots 45-53)

| Slot | Function |
|---|---|
| 45 | **Back** button (red wool) |
| 46-47 | Filler (glass pane) |
| 48 | **Previous Page** (yellow wool) |
| 49 | **Page Indicator** (white wool, e.g. "Page 1/3") |
| 50-52 | Filler (glass pane) |
| 53 | **Next Page** (blue wool) |

### Pagination

- Content area: **45 slots per page** (slots 0-44)
- Navigation row: **9 slots** (slots 45-53)
- Total GUI: **54 slots** (double chest)
- Previous/Next buttons appear only when there are more pages

---

## Sell Hand / Sell All / Sell Undo

### `/sell hand`
- Sells the item currently held in hand
- Price is calculated as `sell_price × item_count`
- Stores the transaction for undo
- Plays sell sound on success, error sound if item is not sellable

### `/sell all`
- Scans entire inventory (all 36 slots)
- Sells every item that has a matching sell price
- Non-sellable items are left untouched
- Stores all sold items for undo
- Shows total earned in a tip message

### `/sell undo`
- Reverses the **last sell** (hand, all, or chest)
- Must be used within the undo time limit (default: 5 minutes)
- Checks:
  - Player has enough money to refund
  - Player has enough inventory space for returned items
- Deducts money and returns all sold items
- Only one undo is stored — selling again overwrites the undo slot

---

## SmartSpawner Integration

When SmartSpawner is installed, the `spawnerz` category in the shop gives **real spawner items** with proper NBT (type + tier), not just generic `52:0` items.

### How it works:
1. Shop entry has `spawner_type` and `spawner_tier` fields
2. On purchase, EconomyGUI calls `SmartSpawner::giveSpawnerItem($player, $type, $tier, $amount)`
3. SmartSpawner creates the item with correct NBT
4. The GUI stays open (doesn't close on purchase)

### Spawnerz Navigation:
```
Categories → Spawnerz → [Mob Type] → [Tier] → Buy Screen
```

11 mob types, each with 4 tiers (Basic, Advanced, Elite, Legendary).

---

## AzCustomEnchant Integration

When AzCustomEnchant is installed, the `custom_enchants` category gives **real enchantment books** with proper NBT.

### How it works:
1. Shop entry has `enchant_key` field (e.g., `excavator`, `vein_miner`)
2. On purchase, EconomyGUI calls `AzCustomEnchant::createEnchantBookItem($key)`
3. The returned book item has the correct NBT tag
4. Item is added to player's inventory

---

## Anti-Dupe System

EconomyGUI uses a **multi-layer anti-dupe system** to prevent players from exploiting the GUI to duplicate items.

### Layer 1: NBT Menu Item Marker
Every item placed in a GUI slot is tagged with a hidden NBT marker:
```
economygui_menu_item = 1 (ByteTag)
```

### Layer 2: Transaction Cancellation
All inventory transactions involving the shop GUI are **cancelled** — the click is treated as a menu action, not a real item move.

### Layer 3: Multi-Event Sweep
The plugin scans the player's real inventory for tagged menu items on **every** relevant event:

| Event | Action |
|---|---|
| `InventoryTransactionEvent` | Sweep after handling click |
| `InventoryCloseEvent` | Sweep on closing ANY inventory |
| `PlayerItemHeldEvent` | Sweep on hotbar slot change |
| `PlayerDropItemEvent` | Cancel drop if item is tagged, then sweep |
| `PlayerInteractEvent` | Cancel interaction if item is tagged, then sweep |
| `PlayerQuitEvent` | Final sweep before inventory is persisted |

### Layer 4: Real Item Display
Shop items are displayed as the **real item** (not a placeholder), so they look exactly like what the player will receive. The NBT marker + sweep system ensures these display items can never be kept.

### Known Limitation
On legacy MCPE 0.14.3/0.15.x clients, client-side drag prediction can briefly render a tagged item in the hotbar before the server's removal packet arrives. The sweep minimizes this window to a single tick but does not eliminate it entirely.

---

## Sound Effects

The plugin uses `LevelEventPacket` to play sounds directly to the player:

| Event | Sound |
|---|---|
| Purchase | EXP Orb pickup |
| Sell | EXP Orb pickup |
| Error (no money, no items, etc.) | Click fail |

Sounds can be disabled in config:
```yaml
sounds:
  enabled: false
```

---

## Transaction Logging

When enabled, all transactions are logged to `plugins/EconomyGUI/transactions.log`:

```
[2026-07-17 16:30:00] [BUY] player1 | 64x Stone | 1920 coins
[2026-07-17 16:30:05] [SELL_HAND] player2 | 1x Diamond | 400 coins
[2026-07-17 16:30:10] [SELL_ALL] player3 | 128x various | 2500 coins
[2026-07-17 16:30:15] [SELL_CHEST] player4 | 64x various | 1200 coins
[2026-07-17 16:30:20] [UNDO] player2 | 1x various | 400 coins
```

Transaction types: `BUY`, `SELL_HAND`, `SELL_ALL`, `SELL_CHEST`, `UNDO`

---

## Admin Commands Reference

### Adding Items

**Shop:**
```
/shopgui-add <id:meta> <price> <amount> [category] [sellprice]
```
- `id:meta` — Item ID and damage value (e.g., `264:0` for Diamond)
- `price` — Buy price per unit
- `amount` — Amount given per purchase
- `category` — Category key (default: "general")
- `sellprice` — Optional sell-back price

**Sell:**
```
/sellgui-add <id:meta> <price> <amount> [category]
```

### Removing Items

```
/shopgui-remove <id:meta> [category]
/sellgui-remove <id:meta> [category]
```
- If category is omitted, removes from ALL categories

### Managing Categories

```
/shopgui-addcat <key> <iconId> <displayName>
/sellgui-addcat <key> <iconId> <displayName>
```
- `key` — Unique category key (lowercase, no spaces)
- `iconId` — Item ID for the category icon
- `displayName` — Display name (supports color codes)

```
/shopgui-removecat <key>
/sellgui-removecat <key>
```
- Removes the category AND all its items

```
/shopgui-listcat
/sellgui-listcat
```
- Lists all categories with their icon, name, and item count

---

## Configuration Reference

### All config.yml Keys

| Key | Type | Default | Description |
|---|---|---|---|
| `shop-title` | string | `"§l§6» §r§eShop §l§6«"` | Title shown in shop GUI |
| `sell-title` | string | `"§l§2» §r§aSell Chest §l§2«"` | Title shown in sell chest |
| `confirm-sell-title` | string | `"§l§e» §r§6Confirm Sell §l§e«"` | Title for confirm sell screen |
| `currency-symbol` | string | `"$"` | Currency symbol |
| `default-category` | string | `"general"` | Default category for new items |
| `default-category-icon` | int | `54` | Default icon for auto-created categories |
| `purchase-cooldown` | int | `0` | Seconds between purchases (0=disabled) |
| `log-transactions` | bool | `true` | Enable transaction logging |
| `undo-time-limit` | int | `300` | Seconds before undo expires |
| `sounds.enabled` | bool | `true` | Enable/disable all sounds |
| `sounds.on-purchase` | string | `"random.orb"` | Sound on purchase |
| `sounds.on-sell` | string | `"random.orb"` | Sound on sell |
| `sounds.on-error` | string | `"mob.villager.no"` | Sound on error |
| `buy-slots` | array | `[{11,1},{13,10},{15,64}]` | Buy button positions & amounts |
| `sell-multipliers` | array | `[{11,1},{13,2},{15,5}]` | Sell multiplier slots |
| `back-button-meta` | int | `14` | Wool color for back button |
| `back-button-name` | string | `"§r§c« Back"` | Back button display name |
| `messages.*` | string | (various) | All 30+ customizable messages |

### All Message Keys

| Key | Default Context |
|---|---|
| `shop-opened` | When shop GUI opens |
| `purchase-success` | After successful purchase |
| `sell-success` | After successful sell |
| `not-enough-money` | Insufficient funds |
| `not-enough-items` | Insufficient items |
| `inventory-full` | Inventory full |
| `no-permission` | Permission denied |
| `shop-reloaded` | Config reloaded |
| `balance` | Balance display |
| `free-purchase` | Free purchase (admin) |
| `item-added` | Item added to shop |
| `item-removed` | Item removed from shop |
| `item-not-found` | Item not found in shop |
| `sell-item-added` | Item added to sell list |
| `sell-item-removed` | Item removed from sell list |
| `sell-item-not-found` | Item not found in sell list |
| `usage-add` | Usage hint for /shopgui-add |
| `usage-remove` | Usage hint for /shopgui-remove |
| `usage-sell-add` | Usage hint for /sellgui-add |
| `usage-sell-remove` | Usage hint for /sellgui-remove |
| `invalid-id` | Invalid id:meta format |
| `sell-chest-success` | Sell chest summary |
| `sell-no-items` | No sellable items |
| `sell-not-sellable` | Item not sellable |
| `sell-returned` | Items returned |
| `sell-cancelled` | Sell cancelled |
| `sell-hand-empty` | Empty hand |
| `sell-hand-success` | Sell hand success |
| `sell-all-success` | Sell all success |
| `sell-no-undo` | No undo available |
| `sell-undo-expired` | Undo time expired |
| `sell-undo-no-money` | Not enough money to undo |
| `sell-undo-no-space` | Not enough space to undo |
| `sell-undo-success` | Undo successful |

---

## Technical Architecture

### Class Structure

```
EconomyGUI/
├── EconomyGUI.php          # Main plugin class (commands, events, GUI logic)
├── gui/
│   ├── BaseGUI.php         # Abstract base for all GUIs (fake chest, navigation)
│   ├── FakeHolder.php      # Virtual InventoryHolder (Vector3-based)
│   ├── OpenContainerTask.php # Delayed container open task (2 ticks)
│   ├── ShopGUI.php         # Shop GUI (categories, items, buy screen)
│   └── SellChestGUI.php    # Sell chest GUI (auto-sell on close)
└── manager/
    └── ConfigManager.php   # Config file management (shop.yml, sell.yml, config.yml)
```

### GUI Rendering (How the Fake Chest Works)

1. **FakeHolder** — A `Vector3` at `(player.x, player.y + 2, player.z)` that implements `InventoryHolder`
2. **onOpen()** — Sends 6 packets in sequence:
   - `UpdateBlockPacket` × 2: Air at both positions (forces client to register change)
   - `UpdateBlockPacket` × 2: Chest blocks at both positions (double chest)
   - `BlockEntityDataPacket` × 2: Tile entity NBT with `pairx/pairy/pairz` for double chest linking
3. **Delayed Open** — After 2 ticks, `ContainerOpenPacket` is sent (gives client time to process blocks)
4. **onClose()** — Restores original blocks (reads `getBlockIdAt`/`getBlockDataAt` before placing chest), sends `ContainerClosePacket`

### Item Display System

- Uses `setItemDisplay()` which writes NBT `display.Name` tag directly (not `setCustomName()`)
- This is because `ItemBlock`-backed items (spawner, wool, glass, etc.) don't implement `setCustomName()` on legacy PocketMine
- `getItemDisplayName()` reads from NBT `display.Name` for reliable name matching in click handlers

### Navigation State Machine

The GUI tracks navigation context through these properties:
- `navContext`: `"categories"` | `"spawner-types"` | `"items"` | `"buy"`
- `currentCategoryKey`: Which category is being viewed
- `currentSpawnerTypeKey`: Which mob type (for spawnerz sub-categories)
- `currentItemEntry`: Which item is being bought
- `currentPage`: Current pagination page (0-based)

---

## Troubleshooting

### "EconomyAPI not found!"
→ Install EconomyAPI plugin. EconomyGUI requires it for all money operations.

### "SmartSpawner plugin is not available"
→ The spawnerz category requires SmartSpawner. Either install it or remove the spawnerz category from shop.yml.

### "AzCustomEnchant plugin is not available"
→ The custom_enchants category requires AzCustomEnchant. Either install it or remove the category.

### Shop GUI opens then immediately closes
→ This is a known issue on MCPE 0.14.3/0.15.x clients. The plugin uses a 2-tick delayed open to fix this. If it persists, ensure the player is not moving when opening the shop.

### Items appear in hotbar briefly then disappear
→ This is the anti-dupe sweep in action. On legacy clients, client-side drag prediction can briefly show a tagged item before the server removes it. This is normal and harmless.

### "Category X already exists"
→ Category keys must be unique. Use `/shopgui-listcat` to see existing keys.

### Config changes not taking effect
→ Use `/shopreload` to reload all config files without restarting the server.

### Back button not working
→ Ensure `back-button-name` in config.yml matches exactly what's displayed. The click handler matches by name string comparison.

### Items showing wrong names in GUI
→ This is a known issue with `ItemBlock` items on legacy PocketMine. The plugin uses NBT-based display names to work around this. If an item still shows its vanilla name, it may need to be added as a non-block item ID.

---

## Credits

- **Author:** Aniss
- **License:** MIT
- **API:** PocketMine-MP 2.0.0
- **Minecraft:** Bedrock Edition 0.14.3 / 0.15.x

---

*EconomyGUI v1.0.0 — Full-featured Shop & Sell GUI for legacy PocketMine-MP servers.*