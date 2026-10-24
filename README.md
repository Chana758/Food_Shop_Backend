# Khmer-Fresh Food Shop: Backend API 🍜

REST API for the Khmer-Fresh restaurant ordering system, built with **Laravel**. It powers the customer storefront and the admin dashboard (POS, orders, payments, reservations).

> ⚠️ **Practice / portfolio project.** All data is fake. Payments are simulated.

🔗 **Live API:** https://food-shop-backend-xivl.onrender.com/api

⏳ Hosted on a free plan. If the server has been idle, the first request can take about 50 seconds while it wakes up.

🖥️ **Frontend repository:** https://github.com/Chana758/Food_Shop_API

🌐 **Live site:** https://food-shop-api.vercel.app

## ⚠️ Demo notice

- This is a demo with **fake data**. Data may be reset or deleted at any time.
- **Do not use real accounts.** Please don't register with your real email, phone number or password. Use the demo accounts below.
- **KHQR is demo only.** No real Bakong account or token is configured, so real payments cannot be made or confirmed.
- Emails are not sent in the demo (`MAIL_MAILER=log`).

## Demo accounts

| Role  | Email                    | Password        |
| ----- | ------------------------ | --------------- |
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
- KHQR payment verification and POS payment flow (demo)
- Table reservations, tables and delivery
- Reviews, favorites and contact messages
- Staff and customer management
- Real-time events with Laravel Reverb
- Reports, scheduled backups and soft delete
- Role-based access (admin, staff, customer) with Laravel Sanctum

## Tech stack

| Layer      | Technology             |
| ---------- | ---------------------- |
| Backend    | Laravel, PHP           |
| Auth       | Laravel Sanctum        |
| Database   | Supabase (PostgreSQL)  |
| Real-time  | Laravel Reverb         |
| Deployment | Docker on Render       |

## Getting started

```bash
git clone https://github.com/Chana758/Food_Shop_Backend.git
cd Food_Shop_Backend

composer install
cp .env.example .env
php artisan key:generate

# Set DB_* values in .env, then:
php artisan migrate --seed
php artisan db:seed --class=DemoAccountsSeeder
php artisan storage:link
php artisan serve
```

## Security

- Never commit `.env` files. Real secrets belong only in your local `.env` or in your hosting provider's environment settings.
- Use your own Bakong account and token only on your local machine, never in a public repository.

## Author

**Chana** · [GitHub](https://github.com/Chana758)