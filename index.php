<?php
/**
 * Jsons: a fake REST API for frontend development, in the spirit of dummyjson.com.
 * One file, vanilla PHP and SQLite, no login, so keep it off the public internet.
 *
 *     php -S 127.0.0.1:8090 index.php
 */

const DB_FILE = __DIR__ . '/jsons.sqlite';
const DEFAULT_LIMIT = 30;
const MAX_DELAY_MS = 10000;
const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

if (!extension_loaded('pdo_sqlite')) {
    http_response_code(500);
    header('Content-Type: text/plain');
    exit('pdo_sqlite is not loaded. Run: sudo apt install php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . "-sqlite3\n");
}

try {
    route();
} catch (Throwable $e) {
    send(['message' => $e->getMessage()], 500);
}

function route(): void
{
    $path = trim($_SERVER['PATH_INFO'] ?? '', '/');
    $page = param('page');

    if ($path !== '' || $page === 'json') {
        cors();
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }
        simulate();
        $path !== '' ? api($path) : raw_json();
    }

    match ($page) {
        'add' => form_page(),
        'edit' => form_page(find('id', param('id')) ?? not_found_page()),
        'save' => save_json(),
        'delete' => delete_json(),
        default => home_page(),
    };
}

/* Database */

function db(): PDO
{
    static $db = null;

    if ($db === null) {
        $db = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('CREATE TABLE IF NOT EXISTS jsons (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            jsons TEXT NOT NULL
        )');
    }

    return $db;
}

function find(string $column, string $value): ?array
{
    $stmt = db()->prepare("SELECT * FROM jsons WHERE $column = ?");
    $stmt->execute([$value]);

    return $stmt->fetch() ?: null;
}

function save_items(int $id, array $items): void
{
    db()->prepare('UPDATE jsons SET jsons = ? WHERE id = ?')->execute([json_encode($items, JSON_FLAGS), $id]);
    db()->exec('COMMIT');
}

/* API */

function cors(): void
{
    header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: ' . ($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '*'));
    header('Vary: Origin');
}

/** ?delay=ms and ?status=code let the frontend exercise its loading and error states. */
function simulate(): void
{
    usleep(1000 * max(0, min(MAX_DELAY_MS, (int) param('delay'))));

    $status = (int) param('status');
    if ($status >= 400 && $status <= 599) {
        send(['message' => "Simulated $status response"], $status);
    }
}

function raw_json(): never
{
    $row = find('id', param('id')) ?? send(['message' => 'Json not found'], 404);
    send_raw($row['jsons']);
}

function api(string $path): never
{
    $parts = explode('/', $path);
    $name = $parts[0];
    $id = $parts[1] ?? null;
    $method = $_SERVER['REQUEST_METHOD'];
    $reading = $method === 'GET' || $method === 'HEAD';

    if (count($parts) > 2) {
        send(['message' => "Route '/$path' not found"], 404);
    }

    // A write rewrites the whole array, so the lock must cover the read too.
    if (!$reading) {
        db()->exec('BEGIN IMMEDIATE');
    }

    $row = find('name', $name) ?? send(['message' => "'$name' not found"], 404);
    // Decoded to objects, not arrays, so that {} is not rewritten as [].
    $items = json_decode($row['jsons']);

    if (!is_array($items)) {
        if ($reading && $id === null) {
            send_raw($row['jsons']);
        }
        send(['message' => "'$name' is not an array, so it has no items"], 400);
    }

    if ($id === null) {
        if ($reading) {
            send_list($name, $items);
        }
        if ($method !== 'POST') {
            send(['message' => "$method needs an item id"], 405);
        }

        $ids = array_filter(array_column($items, 'id'), 'is_numeric');
        $item = (object) (['id' => (int) max([0, ...$ids]) + 1] + (array) read_body());
        $items[] = $item;
        save_items($row['id'], $items);
        send($item, 201);
    }

    $index = find_item($items, $id) ?? send(['message' => "Item '$id' in '$name' not found"], 404);
    $item = $items[$index];

    if ($reading) {
        send(pick($item));
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        $kept = $method === 'PATCH' ? (array) $item : [];
        $items[$index] = (object) array_replace(['id' => $item->id], $kept, (array) read_body(), ['id' => $item->id]);
        save_items($row['id'], $items);
        send($items[$index]);
    }

    if ($method === 'DELETE') {
        array_splice($items, $index, 1);
        save_items($row['id'], $items);
        send((object) array_replace((array) $item, ['isDeleted' => true, 'deletedOn' => date('c')]));
    }

    send(['message' => "$method not allowed"], 405);
}

function send_list(string $name, array $items): never
{
    $query = param('q');
    if ($query !== '') {
        $items = array_filter($items, fn ($item) => contains($item, $query));
    }

    $sortBy = param('sortBy');
    if ($sortBy !== '') {
        $direction = strtolower(param('order')) === 'desc' ? -1 : 1;
        usort($items, fn ($a, $b) => $direction * (($a->$sortBy ?? null) <=> ($b->$sortBy ?? null)));
    }

    $skip = max(0, (int) param('skip'));
    $limit = param('limit') === '' ? DEFAULT_LIMIT : max(0, (int) param('limit'));
    $page = array_slice($items, $skip, $limit === 0 ? null : $limit);

    send([$name => array_map('pick', $page), 'total' => count($items), 'skip' => $skip, 'limit' => count($page)]);
}

/** Searches values only, so that ?q=price does not match every item with a price field. */
function contains(mixed $value, string $query): bool
{
    if (!is_array($value) && !is_object($value)) {
        return stripos((string) $value, $query) !== false;
    }
    foreach ($value as $child) {
        if (contains($child, $query)) {
            return true;
        }
    }

    return false;
}

/** Applies ?select=a,b to an item. Like dummyjson, id is always kept. */
function pick(mixed $item): mixed
{
    $select = param('select');
    if ($select === '' || !is_object($item)) {
        return $item;
    }

    return (object) array_intersect_key((array) $item, array_flip(['id', ...array_map('trim', explode(',', $select))]));
}

function find_item(array $items, string $id): ?int
{
    foreach ($items as $index => $item) {
        if (is_scalar($item->id ?? null) && (string) $item->id === $id) {
            return $index;
        }
    }

    return null;
}

function read_body(): object
{
    $body = json_decode(file_get_contents('php://input'));

    return is_object($body) ? $body : send(['message' => 'Request body must be a JSON object'], 400);
}

function send(mixed $data, int $status = 200): never
{
    send_raw(json_encode($data, JSON_FLAGS), $status);
}

function send_raw(string $json, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo $json;
    exit;
}

/* Web pages */

function save_json(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        go_home();
    }

    $id = (int) ($_POST['id'] ?? 0);
    $name = slug((string) ($_POST['name'] ?? ''));
    // Browsers submit textarea line breaks as CRLF. JSON strings cannot hold raw ones, so this is safe.
    $json = str_replace("\r\n", "\n", trim((string) ($_POST['json'] ?? '')));
    $taken = find('name', $name);
    json_decode($json);

    $error = match (true) {
        $name === '' || $json === '' => 'Fill all fields. The name needs at least one letter or number.',
        json_last_error() !== JSON_ERROR_NONE => 'Invalid JSON: ' . json_last_error_msg(),
        $taken && (int) $taken['id'] !== $id => "The name '$name' is already used.",
        default => null,
    };

    if ($error !== null) {
        http_response_code(422);
        form_page(['id' => $id, 'name' => $_POST['name'] ?? '', 'jsons' => $_POST['json'] ?? ''], $error);
        return;
    }

    if ($id > 0) {
        db()->prepare('UPDATE jsons SET name = ?, jsons = ? WHERE id = ?')->execute([$name, $json, $id]);
    } else {
        db()->prepare('INSERT INTO jsons (name, jsons) VALUES (?, ?)')->execute([$name, $json]);
    }

    go_home();
}

function delete_json(): never
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        db()->prepare('DELETE FROM jsons WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
    }

    go_home();
}

function go_home(): never
{
    header('Location: ?page=home');
    exit;
}

/** Names become URLs: "Camera 1" is served at /camera-1. */
function slug(string $name): string
{
    return trim(preg_replace('/[^a-z0-9_]+/', '-', strtolower($name)), '-');
}

function param(string $key): string
{
    $value = $_GET[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

/** Without URL rewriting, Apache only reaches this script through /index.php/... */
function api_url(string $name): string
{
    $script = $_SERVER['SCRIPT_NAME'];
    $base = PHP_SAPI === 'cli-server' ? rtrim(dirname($script), '/\\') : $script;
    $scheme = empty($_SERVER['HTTPS']) ? 'http' : 'https';

    return "$scheme://{$_SERVER['HTTP_HOST']}$base/$name";
}

function page_head(string $title): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: #111; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        a { color: inherit; }
        [hidden] { display: none !important; }
        :focus-visible { outline: 2px solid #000; outline-offset: 2px; }
        input:focus-visible, textarea:focus-visible, select:focus-visible { outline-offset: -1px; }

        .wrap { max-width: 1100px; margin: 0 auto; padding-inline: 24px; }
        nav { border-bottom: 1px solid #e5e5e5; }
        nav .wrap { display: flex; align-items: center; justify-content: space-between; height: 64px; }
        .brand { font-size: 20px; font-weight: 600; text-decoration: none; }
        main { padding-block: 32px; }

        .btn { display: inline-block; padding: 3px 10px; border: 1px solid #000; border-radius: 4px; background: #000; color: #fff; font: inherit; font-size: 13px; text-decoration: none; cursor: pointer; }
        .btn:hover { border-color: #333; background: #333; }
        .btn-outline { background: #fff; color: #000; }
        .btn-outline:hover { border-color: #000; background: #eee; }

        .table-scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 8px; border-bottom: 1px solid #e5e5e5; font-weight: 400; text-align: left; white-space: nowrap; }
        thead th { font-weight: 600; }
        .empty { padding: 32px; color: #666; text-align: center; }

        dialog { width: min(400px, calc(100vw - 32px)); padding: 0; border: 1px solid #e5e5e5; border-radius: 8px; color: inherit; }
        dialog::backdrop { background: rgba(0, 0, 0, .4); }
        dialog form { padding: 20px; }
        dialog p { margin: 0 0 20px; overflow-wrap: anywhere; }
        .actions { display: flex; justify-content: flex-end; gap: 8px; }

        details { margin-top: 24px; }
        summary { font-weight: 600; cursor: pointer; }
        pre { margin: 16px 0 0; padding: 16px; overflow-x: auto; border-radius: 6px; background: #f7f7f7; font-size: 14px; }

        .field { margin-bottom: 20px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; }
        input[type=text], textarea { width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 6px; background: #fff; color: inherit; font: inherit; }
        textarea { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 14px; resize: vertical; }
        .hint { margin-top: 6px; color: #666; font-size: 14px; }
        .alert { margin-bottom: 20px; padding: 12px 16px; border: 1px solid #000; border-radius: 6px; }

        .editor-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-bottom: 8px; }
        .editor-status { margin-left: auto; color: #666; font-size: 13px; }
        .btn:disabled { opacity: .4; cursor: default; }

        .tree { max-height: 65vh; padding: 8px; overflow: auto; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        .tree input[type=text], .tree input[type=number], .tree textarea, .tree select { padding: 3px 6px; border: 1px solid #e5e5e5; border-radius: 4px; background: #fff; color: inherit; font: inherit; }
        .tree textarea { field-sizing: content; resize: none; }
        .tree .node-key { flex: none; width: 130px; font-weight: 600; }
        .tree .node-value { flex: 1; width: auto; min-width: 0; margin: 0; }
        .node-row { display: flex; align-items: flex-start; gap: 6px; padding: 2px 0; }
        .node-children { margin-left: 10px; padding-left: 14px; border-left: 1px solid #e5e5e5; }
        .node-caret { flex: none; width: 20px; height: 29px; padding: 0; border: 0; background: none; color: inherit; cursor: pointer; }
        span.node-caret { cursor: default; }
        .node-index { flex: none; width: 28px; color: #666; font-size: 13px; line-height: 29px; }
        .node-boolean { display: flex; align-items: center; gap: 6px; height: 29px; font-weight: 400; }
        .node-boolean input { accent-color: #000; }
        .node-null { color: #666; line-height: 29px; }
        .node-summary { overflow: hidden; height: 29px; padding: 0 6px; border: 0; background: none; color: inherit; font: inherit; text-align: left; text-overflow: ellipsis; white-space: nowrap; cursor: pointer; }
        .tree .node-type { flex: none; width: 84px; height: 29px; color: #666; font-size: 13px; }
        .node-delete { flex: none; width: 24px; height: 29px; padding: 0; border: 0; border-radius: 4px; background: none; color: #666; font-size: 16px; cursor: pointer; }
        .node-delete:hover { background: #000; color: #fff; }
        .node-add { margin: 4px 0 8px 26px; }

        @media (max-width: 600px) {
            .tree .node-key { flex: 1; width: auto; min-width: 0; }
            .tree .node-value { flex-basis: 100%; order: 1; }
            .node-row { flex-wrap: wrap; }
            .node-children { margin-left: 4px; padding-left: 8px; }
            span.node-caret { display: none; }
        }

        .not-found { padding: 64px 0; text-align: center; }
        .not-found h1 { margin: 0; font-size: 72px; font-weight: 300; }
    </style>
</head>
<body>
<nav>
    <div class="wrap">
        <a class="brand" href="?page=home">Jsons</a>
        <a class="btn" href="?page=add">Add Json</a>
    </div>
</nav>
<main class="wrap">
    <?php
}

function page_foot(): void
{
    ?>
</main>
</body>
</html>
    <?php
}

function home_page(): void
{
    $rows = db()->query('SELECT id, name FROM jsons ORDER BY id')->fetchAll();
    $example = api_url($rows[0]['name'] ?? 'products');

    page_head('Jsons');
    ?>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th scope="col">#</th>
                    <th scope="col">Name</th>
                    <th scope="col">Json</th>
                    <th scope="col">Fetch Url Endpoint</th>
                    <th scope="col">Edit</th>
                    <th scope="col">Delete</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $number => $row): ?>
                    <tr>
                        <th scope="row"><?= $number + 1 ?></th>
                        <td><?= h($row['name']) ?></td>
                        <td><a href="?page=json&amp;id=<?= $row['id'] ?>" class="btn">JSON</a></td>
                        <td><a href="<?= h(api_url($row['name'])) ?>"><?= h(api_url($row['name'])) ?></a></td>
                        <td><a href="?page=edit&amp;id=<?= $row['id'] ?>" class="btn">Edit</a></td>
                        <td><button type="button" class="btn" data-id="<?= $row['id'] ?>" data-name="<?= h($row['name']) ?>">Delete</button></td>
                    </tr>
                <?php endforeach ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="empty">No data yet. Use Add Json to create one.</td></tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <details>
        <summary>API reference</summary>
        <pre><?= h("GET    $example        list: {items, total, skip, limit}
GET    $example/1      one item
POST   $example        add an item, the id is assigned for you
PUT    $example/1      replace an item
PATCH  $example/1      update some fields of an item
DELETE $example/1      remove an item

List:  ?limit=10 ?skip=10 ?q=text ?sortBy=price ?order=desc ?select=title,price
       The default limit is " . DEFAULT_LIMIT . ", limit=0 returns everything.
Any:   ?delay=1500 waits before responding, ?status=500 forces an error.
Raw:   The JSON button returns the stored JSON exactly as you saved it.") ?></pre>
    </details>

    <dialog id="delete-dialog">
        <form action="?page=delete" method="post">
            <p>Delete <strong id="delete-name"></strong>? Its endpoint will stop working and this cannot be undone.</p>
            <input type="hidden" name="id" id="delete-id">
            <div class="actions">
                <button type="submit" class="btn btn-outline" formmethod="dialog">Cancel</button>
                <button type="submit" class="btn">Delete</button>
            </div>
        </form>
    </dialog>
    <script>
        const dialog = document.getElementById('delete-dialog');

        document.querySelectorAll('button[data-id]').forEach((button) => {
            button.addEventListener('click', () => {
                document.getElementById('delete-id').value = button.dataset.id;
                document.getElementById('delete-name').textContent = button.dataset.name;
                dialog.showModal();
            });
        });

        // The form fills the dialog, so a click landing on the dialog itself is on the backdrop.
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    </script>
    <?php
    page_foot();
}

function form_page(array $row = [], string $error = ''): void
{
    $id = (int) ($row['id'] ?? 0);

    page_head($id > 0 ? 'Edit Json' : 'Add Json');
    ?>
    <form action="?page=save" method="post">
        <?php if ($error !== ''): ?>
            <div class="alert" role="alert"><?= h($error) ?></div>
        <?php endif ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="field">
            <label for="name">Name</label>
            <input type="text" name="name" id="name" value="<?= h($row['name'] ?? '') ?>" required>
            <div class="hint">This becomes the URL, so "Camera 1" is served at /camera-1.</div>
        </div>
        <div class="field">
            <label for="json">Json</label>
            <div class="editor-bar" id="json-bar" hidden>
                <button type="button" class="btn" data-tab="visual">Visual</button>
                <button type="button" class="btn btn-outline" data-tab="raw">Raw</button>
                <button type="button" class="btn btn-outline" id="json-undo" disabled>Undo</button>
                <button type="button" class="btn btn-outline" id="json-format" hidden>Format</button>
                <span class="editor-status" id="json-status" role="status"></span>
            </div>
            <div class="tree" id="json-tree" hidden></div>
            <textarea name="json" id="json" rows="18" required placeholder='[{"id": 1, "title": "Phone", "price": 199}]'><?= h($row['jsons'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn">Submit</button>
    </form>
    <script>
        // The textarea stays the form's field, the tree only writes to it. Text that is
        // never edited in the tree is therefore saved exactly as it was typed.
        (() => {
            const TYPES = ['string', 'number', 'boolean', 'null', 'object', 'array'];
            const PAGE_SIZE = 100;
            const textarea = document.getElementById('json');
            const tree = document.getElementById('json-tree');
            const status = document.getElementById('json-status');
            const undoButton = document.getElementById('json-undo');
            const formatButton = document.getElementById('json-format');
            const tabs = document.querySelectorAll('[data-tab]');

            // The root sits under a key so that it is read and written like any other child.
            const root = { value: null };
            const expanded = new WeakSet();
            const visibleCount = new WeakMap();
            const undoStack = [];
            let mode = 'visual';
            let startText = '';
            let treeText = null;
            let committedText = '';
            let focusRequest = null;

            const isContainer = (value) => typeof value === 'object' && value !== null;
            const typeOf = (value) => (value === null ? 'null' : Array.isArray(value) ? 'array' : typeof value);

            function el(tag, props = {}, ...children) {
                const node = Object.assign(document.createElement(tag), props);
                node.append(...children);
                return node;
            }

            // JavaScript rounds integers this big, so rewriting them from the tree would change them.
            function hasUnsafeNumber(value) {
                if (isContainer(value)) {
                    return Object.values(value).some(hasUnsafeNumber);
                }
                return Number.isInteger(value) && !Number.isSafeInteger(value);
            }

            function parse(text) {
                const value = JSON.parse(text);
                if (hasUnsafeNumber(value)) {
                    throw new Error('Numbers this large can only be edited in Raw.');
                }
                return value;
            }

            function showStatus() {
                if (mode === 'visual') {
                    status.textContent = textarea.value === startText ? '' : 'Unsaved changes';
                    return;
                }
                try {
                    parse(textarea.value);
                    status.textContent = 'Valid JSON';
                } catch (error) {
                    status.textContent = (error instanceof SyntaxError ? 'Invalid JSON: ' : '') + error.message;
                }
            }

            function showMode(next) {
                mode = next;
                tree.hidden = next !== 'visual';
                undoButton.hidden = next !== 'visual';
                textarea.hidden = next !== 'raw';
                formatButton.hidden = next !== 'raw';
                tabs.forEach((tab) => tab.classList.toggle('btn-outline', tab.dataset.tab !== next));
                showStatus();
            }

            function load(text) {
                root.value = parse(text);
                treeText = text;
                if (isContainer(root.value)) {
                    expanded.add(root.value);
                }
                render();
            }

            function openVisual() {
                if (textarea.value !== treeText) {
                    try {
                        load(textarea.value);
                    } catch {
                        showMode('raw');
                        return;
                    }
                    undoStack.length = 0;
                    undoButton.disabled = true;
                    committedText = textarea.value;
                }
                showMode('visual');
            }

            function writeText() {
                textarea.value = treeText = JSON.stringify(root.value, null, 2);
                showStatus();
            }

            function commit() {
                if (textarea.value !== committedText) {
                    undoStack.push(committedText);
                    committedText = textarea.value;
                    undoButton.disabled = false;
                }
            }

            function apply(change) {
                change();
                writeText();
                commit();
                render();
            }

            function render() {
                const scrollTop = tree.scrollTop;
                tree.replaceChildren(renderNode(root, 'value'));
                tree.scrollTop = scrollTop;

                const requested = tree.querySelector('[data-focus]');
                if (requested) {
                    requested.scrollIntoView({ block: 'nearest' });
                    const target = requested.querySelector(requested.dataset.focus);
                    target?.focus();
                    target?.select();
                }
                focusRequest = null;
            }

            function renderNode(parent, key) {
                const slot = { parent, key };
                const value = parent[key];
                const isRoot = parent === root;
                const isOpen = isContainer(value) && expanded.has(value);
                const node = el('div', { className: 'node' });
                const row = el('div', { className: 'node-row' });

                const toggle = () => {
                    expanded[expanded.has(value) ? 'delete' : 'add'](value);
                    const fresh = renderNode(slot.parent, slot.key);
                    node.replaceWith(fresh);
                    fresh.querySelector('.node-caret').focus();
                };

                row.append(isContainer(value)
                    ? el('button', { type: 'button', className: 'node-caret', textContent: isOpen ? '▾' : '▸', title: isOpen ? 'Collapse' : 'Expand', onclick: toggle })
                    : el('span', { className: 'node-caret' }));
                if (!isRoot) {
                    row.append(Array.isArray(parent) ? el('span', { className: 'node-index', textContent: key }) : keyInput(slot));
                }
                row.append(valueEditor(slot, toggle), typeSelect(slot));
                if (!isRoot) {
                    row.append(deleteButton(slot));
                }

                node.append(row);
                if (isOpen) {
                    node.append(renderChildren(value));
                }
                if (focusRequest?.parent === parent && focusRequest.key === key) {
                    node.dataset.focus = focusRequest.selector;
                }
                return node;
            }

            function renderChildren(container) {
                const isList = Array.isArray(container);
                const keys = Object.keys(container);
                const visible = visibleCount.get(container) ?? PAGE_SIZE;
                const children = el('div', { className: 'node-children' });

                keys.slice(0, visible).forEach((key) => children.append(renderNode(container, isList ? Number(key) : key)));
                if (keys.length > visible) {
                    const onclick = () => {
                        visibleCount.set(container, visible + PAGE_SIZE);
                        render();
                    };
                    children.append(el('button', { type: 'button', className: 'btn btn-outline node-add', textContent: `Show more (${keys.length - visible} left)`, onclick }));
                }
                children.append(addButton(container));
                return children;
            }

            function keyInput(slot) {
                const input = el('input', { type: 'text', className: 'node-key', value: slot.key, title: 'Field name', spellcheck: false });

                input.onchange = () => {
                    const name = input.value.trim();
                    showStatus();
                    if (name === '' || Object.hasOwn(slot.parent, name)) {
                        if (name !== '' && name !== slot.key) {
                            status.textContent = `The field "${name}" already exists.`;
                        }
                        input.value = slot.key;
                        return;
                    }
                    renameKey(slot.parent, slot.key, name);
                    slot.key = name;
                    writeText();
                };
                return input;
            }

            // Rebuilt in place so that the field keeps its position and the object its identity.
            function renameKey(object, from, to) {
                const entries = Object.entries(object);
                entries.forEach(([key]) => delete object[key]);
                entries.forEach(([key, value]) => { object[key === from ? to : key] = value; });
            }

            function valueEditor(slot, toggle) {
                const value = slot.parent[slot.key];
                const set = (next) => {
                    slot.parent[slot.key] = next;
                    writeText();
                };

                if (typeof value === 'string') {
                    const input = el('textarea', { rows: 1, className: 'node-value', value, title: 'Value', spellcheck: false });
                    input.oninput = () => set(input.value);
                    return input;
                }
                if (typeof value === 'number') {
                    const input = el('input', { type: 'number', step: 'any', className: 'node-value', value, title: 'Value' });
                    input.oninput = () => {
                        if (input.value !== '') {
                            set(input.valueAsNumber);
                        }
                    };
                    input.onblur = () => { input.value = slot.parent[slot.key]; };
                    return input;
                }
                if (typeof value === 'boolean') {
                    const text = document.createTextNode(String(value));
                    const input = el('input', { type: 'checkbox', checked: value });
                    input.onchange = () => {
                        text.data = String(input.checked);
                        set(input.checked);
                    };
                    return el('label', { className: 'node-value node-boolean' }, input, text);
                }
                if (value === null) {
                    return el('span', { className: 'node-value node-null', textContent: 'null' });
                }
                return el('button', { type: 'button', className: 'node-value node-summary', textContent: summary(value), onclick: toggle });
            }

            function summary(value) {
                if (Array.isArray(value)) {
                    return `${value.length} items`;
                }
                const count = `${Object.keys(value).length} fields`;
                const label = Object.values(value).find((child) => typeof child === 'string' && child !== '');
                return label ? `${label} · ${count}` : count;
            }

            function typeSelect(slot) {
                const current = typeOf(slot.parent[slot.key]);
                const options = TYPES.map((type) => el('option', { value: type, textContent: type, selected: type === current }));
                const select = el('select', { className: 'node-type', title: 'Type' }, ...options);

                select.onchange = () => apply(() => {
                    const next = convert(slot.parent[slot.key], select.value);
                    slot.parent[slot.key] = next;
                    if (isContainer(next)) {
                        expanded.add(next);
                    }
                });
                return select;
            }

            function convert(value, type) {
                switch (type) {
                    case 'string': return isContainer(value) ? '' : String(value ?? '');
                    case 'number': return Number(value) || 0;
                    case 'boolean': return Boolean(value);
                    case 'null': return null;
                    case 'array': return [];
                    default: return {};
                }
            }

            function deleteButton(slot) {
                const onclick = () => apply(() => {
                    if (Array.isArray(slot.parent)) {
                        slot.parent.splice(slot.key, 1);
                    } else {
                        delete slot.parent[slot.key];
                    }
                });
                return el('button', { type: 'button', className: 'node-delete', textContent: '×', title: 'Delete', onclick });
            }

            function addButton(container) {
                const isList = Array.isArray(container);
                const onclick = () => apply(() => {
                    if (isList) {
                        const item = newItem(container);
                        container.push(item);
                        if (isContainer(item)) {
                            expanded.add(item);
                        }
                        focusRequest = { parent: container, key: container.length - 1, selector: 'textarea' };
                    } else {
                        const key = freeName(container);
                        container[key] = '';
                        focusRequest = { parent: container, key, selector: '.node-key' };
                    }
                    visibleCount.set(container, Object.keys(container).length);
                });
                return el('button', { type: 'button', className: 'btn btn-outline node-add', textContent: isList ? '+ Add item' : '+ Add field', onclick });
            }

            /** A new item copies the last one's shape, with empty values and the next id. */
            function newItem(items) {
                if (items.length === 0) {
                    return items === root.value ? { id: 1 } : '';
                }
                const item = blank(items[items.length - 1]);
                if (typeof item?.id === 'number') {
                    item.id = Math.max(0, ...items.map((other) => Number(other?.id) || 0)) + 1;
                }
                return item;
            }

            function blank(value) {
                if (Array.isArray(value)) {
                    return [];
                }
                if (isContainer(value)) {
                    return Object.fromEntries(Object.entries(value).map(([key, child]) => [key, blank(child)]));
                }
                return { string: '', number: 0, boolean: false }[typeof value] ?? null;
            }

            function freeName(object) {
                let name = 'field';
                for (let number = 2; Object.hasOwn(object, name); number++) {
                    name = `field${number}`;
                }
                return name;
            }

            tabs.forEach((tab) => {
                tab.onclick = () => (tab.dataset.tab === 'visual' ? openVisual() : showMode('raw'));
            });

            undoButton.onclick = () => {
                textarea.value = committedText = undoStack.pop();
                undoButton.disabled = undoStack.length === 0;
                load(textarea.value);
                showStatus();
            };

            formatButton.onclick = () => {
                try {
                    textarea.value = JSON.stringify(parse(textarea.value), null, 2);
                } catch {
                    // showStatus below reports what is wrong with the text.
                }
                showStatus();
            };

            textarea.addEventListener('input', showStatus);

            // Typing is committed as one undo step when the field loses focus.
            tree.addEventListener('change', commit);

            // Enter would otherwise submit the form from a field, or add a line to a one-line value.
            tree.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && !event.shiftKey && event.target.matches('input, textarea')) {
                    event.preventDefault();
                }
            });

            if (textarea.value.trim() === '') {
                textarea.value = '[]';
            }
            startText = textarea.value;
            document.getElementById('json-bar').hidden = false;
            openVisual();
        })();
    </script>
    <?php
    page_foot();
}

function not_found_page(): never
{
    http_response_code(404);
    page_head('404 Not Found');
    ?>
    <div class="not-found">
        <h1>404</h1>
        <p>The page you are looking for was not found.</p>
        <a href="?page=home" class="btn">Back to Home</a>
    </div>
    <?php
    page_foot();
    exit;
}
