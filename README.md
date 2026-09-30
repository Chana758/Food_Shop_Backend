# Khmer-Fresh Food Shop: Backend API 🍜

REST API for the Khmer-Fresh restaurant ordering system, built with **Laravel**. It powers the customer storefront and the admin dashboard (POS, orders, payments, reservations).

🔗 **Live API:** https://food-shop-backend-xivl.onrender.com/api

> ⏳ Hosted on a free plan. If the server has been idle, the first request can take about 50 seconds while it wakes up.

🖥️ **Frontend repository:** https://github.com/Chana758/Food_Shop_API
🌐 **Live site:** https://food-shop-api.vercel.app

## Features

- Products and categories (discount price with expiry, stock tracking)
- Orders, payments and receipts
- KHQR payment verification and POS payment flow
- Table reservations, tables and delivery
- Reviews, favorites and contact messages
- Staff and customer management
- Real-time events with Laravel Reverb
- Reports, backup and soft-delete (trash)

## Tech stack

| Layer      | Technology            |
| ---------- | --------------------- |
| Backend    | Laravel, PHP          |
| Database   | Supabase (PostgreSQL) |
| Real-time  | Laravel Reverb        |
| Deployment | Docker on Render      |

## Example endpoints

| Method | Endpoint             | Description     |
| ------ | -------------------- | --------------- |
| GET    | `/api/products`      | List products   |
| GET    | `/api/products/{id}` | Product details |
| GET    | `/api/categories`    | List categories |
| GET    | `/api/search?q=`     | Search products |

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
php artisan reverb:start   # optional: real-time server
```

## Deployment notes

- Never commit your real `.env`. Set variables in Render's **Environment** tab.
- Render's free plan has ephemeral storage: uploaded files are lost on redeploy. Use external storage (e.g. Supabase Storage) for images.
- Reverb needs its own long-running process. On Render use `BROADCAST_DRIVER=log` or a hosted service such as Pusher.

## Author

**Chana** · [GitHub](https://github.com/Chana758)
