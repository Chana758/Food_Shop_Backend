# Khmer-Fresh Food Shop: Backend API 🍜

REST API for the Khmer-Fresh restaurant ordering system, built with **Laravel**. It powers the customer storefront and the admin dashboard (POS, orders, payments, reservations).

🔗 **Live API:** https://food-shop-backend-xivl.onrender.com/api

> ⏳ Hosted on a free plan. If the server has been idle, the first request can take about 50 seconds while it wakes up.

🖥️ **Frontend repository:** https://github.com/Chana758/Food_Shop_API
🌐 **Live site:** https://food-shop-api.vercel.app

## Demo accounts

| Role  | Email                    | Password         |
| ----- | ------------------------ | ---------------- |
| Admin | `demo-admin@example.com` | `DemoAdmin@2026` |
| Staff | `demo-staff@example.com` | `DemoStaff@2026` |
| User  | `demo-user@example.com`  | `DemoUser@2026`  |

Create them with:

```bash
php artisan db:seed --class=DemoAccountsSeeder
```

## Features

- Products and categories with discounts and stock tracking
- Orders, payments and receipts
- KHQR payment verification and POS payment flow
- Table reservations, tables and delivery
- Reviews, favorites and contact messages
- Staff and customer management
- Real-time events with Laravel Reverb
- Reports, scheduled backups and soft delete

## Tech stack

| Layer      | Technology            |
| ---------- | --------------------- |
| Backend    | Laravel, PHP          |
| Database   | Supabase (PostgreSQL) |
| Real-time  | Laravel Reverb        |
| Deployment | Docker on Render      |

## Getting started

```bash
git clone https://github.com/Chana758/Food_Shop_Backend.git
cd Food_Shop_Backend

composer install
cp .env.example .env
php artisan key:generate

# Set DB_* values in .env, then:
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

## Author

**Chana** · [GitHub](https://github.com/Chana758)
