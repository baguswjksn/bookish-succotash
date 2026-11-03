# Jsons

A fake REST API for frontend development, in the spirit of [dummyjson.com](https://dummyjson.com/).
One PHP file and SQLite, with no login and no dependencies.

Save any JSON from the web page and it becomes an endpoint you can `fetch` from
your frontend. Unlike dummyjson, writes are real: a `POST` is still there on the
next `GET`.

There is no authentication and CORS is open to every origin, so anyone who can
reach it can read and change the data. Keep it on localhost.

## Running

Needs PHP 8.1+ with `pdo_sqlite`:

    sudo apt install php8.3-sqlite3

Then start it from this folder and open <http://127.0.0.1:8090>:

    php -S 127.0.0.1:8090 index.php

The database is created automatically at `jsons.sqlite`.

`php -S` handles one request at a time, so a request using `?delay=` holds up
everything behind it. If your frontend sends requests in parallel, add workers:

    PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8090 index.php

### Apache or XAMPP

Copy the folder into `htdocs` and make it writable by the web server. There is
no `.htaccess`, so API URLs go through `index.php`, for example
`http://localhost/jsons-main/index.php/products`. The home page shows the right
URL for each endpoint.

## Editing

The form opens in **Visual**, a tree where you expand items, edit values and add
or delete fields. **+ Add item** copies the shape of the last item with empty
values and the next `id`. Long lists show 100 rows at a time. Nothing is stored
until you press Submit.

**Raw** is the plain JSON text, checked as you type, with a Format button. The
page opens in Raw when the text cannot be shown as a tree, either because it is
invalid or because it holds integers too large for JavaScript to keep exact.

The tree only rewrites the text once you change something, and it then formats
it with 2-space indentation.

## API

Save a JSON array named `products`, then:

| Request               | Result                                     |
| --------------------- | ------------------------------------------ |
| `GET /products`       | `{"products": [...], total, skip, limit}`  |
| `GET /products/1`     | The item whose `id` is 1                   |
| `POST /products`      | Adds an item and assigns its `id`          |
| `PUT /products/1`     | Replaces the item, keeping its `id`        |
| `PATCH /products/1`   | Updates only the fields you send           |
| `DELETE /products/1`  | Removes the item, returns it with `isDeleted` and `deletedOn` |

Request bodies must be JSON objects. Items are matched by their `id` field, so
items without one can be listed but not addressed one by one.

A saved JSON that is an object rather than an array is read-only:
`GET /name` returns it as it is.

### List parameters

| Parameter            | Example                | Notes                                   |
| -------------------- | ---------------------- | --------------------------------------- |
| `limit`              | `?limit=10`            | Default 30, `0` returns everything      |
| `skip`               | `?skip=10`             |                                         |
| `q`                  | `?q=phone`             | Searches values, case-insensitive       |
| `sortBy`, `order`    | `?sortBy=price&order=desc` | `order` is `asc` by default         |
| `select`             | `?select=title,price`  | `id` is always kept. Also works on `GET /products/1` |

### Testing loading and error states

These work on every API URL:

| Parameter | Example        | Effect                                   |
| --------- | -------------- | ---------------------------------------- |
| `delay`   | `?delay=1500`  | Waits this many ms, up to 10000          |
| `status`  | `?status=500`  | Responds with this 4xx or 5xx status and changes nothing |

### Raw JSON

`GET /?page=json&id=4` returns the stored JSON exactly as you saved it, with no
envelope and no limit. Use it to mirror the exact response shape of a real
backend. The JSON button on the home page links to it.

## Example

```js
const products = await fetch('http://127.0.0.1:8090/products?limit=10&sortBy=price')
  .then((response) => response.json());

await fetch('http://127.0.0.1:8090/products', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ title: 'New product', price: 10 }),
});
```
