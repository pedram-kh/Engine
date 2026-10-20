<?php

declare(strict_types=1);

/**
 * AH-104a Stage 0 — audit-action / audit-metadata enumeration.
 *
 * Static analysis only (nikic/php-parser). It never boots Laravel, never
 * touches a database and never executes application code, so it is safe to
 * run anywhere, including against a production checkout.
 *
 * Usage (from apps/api):
 *
 *     php tools/enumerate-audit-actions.php > ../../docs/reviews/account-deletion-audit-enumeration.md
 *
 * What it reports (facts only — the reading of them lives in the plan):
 *   1. Every `AuditAction` case: value, requiresReason, every direct call
 *      site, every other reference, and the literal keys passed in
 *      `metadata:` / `before:` / `after:`.
 *   2. Every audit call whose action or payload is not statically readable
 *      (flagged "needs a manual read").
 *   3. Every model using the `Audited` trait: its `auditableAllowlist()`
 *      and the actions the trait derives for it.
 *   4. The union of all literal payload keys, flagged against the PII-map
 *      §4.1 scrub list and a PII-name heuristic.
 *
 * Output is deterministic (sorted); the header carries the git SHA.
 */

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;

$apiRoot = dirname(__DIR__);
require $apiRoot.'/vendor/autoload.php';

const SCRUB_LIST = [
    'email', 'name', 'last_name', 'last_login_ip', 'suspended_reason',
    'display_name', 'provider_account_id',
];
const PII_HEURISTIC = '/email|name|phone|address|(^|_)ip($|_)|token|handle|birth|reason|note|url|tax|iban|account|^(before|after|field|value|message|text|body|content|comment|welcome_message)$/i';
const LOG_PARAMS = [
    'action', 'actor', 'subject', 'reason', 'before', 'after', 'metadata',
    'agencyId', 'actorType', 'actorRole', 'ip', 'userAgent',
];
const PAYLOAD_PARAMS = ['metadata', 'before', 'after'];

$printer = new Printer;
$parser = (new ParserFactory)->createForNewestSupportedVersion();
$finder = new NodeFinder;

/** @return list<string> */
function phpFiles(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $out[] = $f->getPathname();
        }
    }
    sort($out);

    return $out;
}

function rel(string $apiRoot, string $path): string
{
    return 'apps/api/'.ltrim(substr($path, strlen($apiRoot)), '/');
}

function isAuditActionName(?Node $n): bool
{
    return $n instanceof Node\Name
        && ($n->getLast() === 'AuditAction');
}

/**
 * Describe an argument payload as literal keys + dynamic parts.
 *
 * @return array{keys: list<string>, dynamic: list<string>}
 */
function describePayload(?Node $expr, Printer $printer): array
{
    $keys = [];
    $dyn = [];
    $walk = function (?Node $e) use (&$walk, &$keys, &$dyn, $printer): void {
        if ($e === null) {
            return;
        }
        if ($e instanceof Node\Expr\ConstFetch && strtolower($e->name->toString()) === 'null') {
            return;
        }
        if ($e instanceof Node\Expr\Array_) {
            foreach ($e->items as $item) {
                if ($item->unpack) {
                    $walk($item->value);

                    continue;
                }
                if ($item->key === null) {
                    $dyn[] = '[list item] '.$printer->prettyPrintExpr($item->value);

                    continue;
                }
                if ($item->key instanceof Node\Scalar\String_) {
                    $keys[] = $item->key->value;
                } else {
                    $dyn[] = 'key ['.$printer->prettyPrintExpr($item->key).']';
                }
            }

            return;
        }
        if ($e instanceof Node\Expr\FuncCall && $e->name instanceof Node\Name && $e->name->toString() === 'array_merge') {
            foreach ($e->args as $a) {
                $walk($a->value);
            }

            return;
        }
        // array_filter(<array>, <callback>) keeps the keys of its first argument.
        if ($e instanceof Node\Expr\FuncCall && $e->name instanceof Node\Name && $e->name->toString() === 'array_filter' && isset($e->args[0])) {
            $walk($e->args[0]->value);

            return;
        }
        // cond ? [..] : [..]  — both branches are possible payloads.
        if ($e instanceof Node\Expr\Ternary) {
            $walk($e->if ?? $e->cond);
            $walk($e->else);

            return;
        }
        $dyn[] = $printer->prettyPrintExpr($e);
    };
    $walk($expr);
    $keys = array_values(array_unique($keys));
    sort($keys);

    return ['keys' => $keys, 'dynamic' => $dyn];
}

/** Resolve an argument list into a name => Node map using the AuditLogger::log signature. */
function mapArgs(array $args): array
{
    $map = [];
    $pos = 0;
    foreach ($args as $arg) {
        if ($arg->unpack) {
            $map['__spread'] = $arg->value;

            continue;
        }
        if ($arg->name !== null) {
            $map[$arg->name->toString()] = $arg->value;
        } else {
            $map[LOG_PARAMS[$pos] ?? 'extra'.$pos] = $arg->value;
            $pos++;
        }
    }

    return $map;
}

/** Is this node a call to AuditLogger::log (facade, injected, or static accessor)? */
function isAuditLogCall(Node $n, Printer $printer): bool
{
    if ($n instanceof Node\Expr\StaticCall && $n->name instanceof Node\Identifier && $n->name->toString() === 'log') {
        return $n->class instanceof Node\Name && in_array($n->class->getLast(), ['Audit', 'AuditLogger'], true);
    }
    if ($n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier && $n->name->toString() === 'log') {
        return (bool) preg_match('/audit/i', $printer->prettyPrintExpr($n->var));
    }

    return false;
}

final class Collector extends NodeVisitorAbstract
{
    public string $file = '';

    /** @var list<array<string,mixed>> */
    public array $calls = [];

    /** @var list<array{file:string,line:int,case:string,id:int}> */
    public array $refs = [];

    /** @var list<array{file:string,line:int,fn:string,expr:string}> */
    public array $dynamicResolvers = [];

    /** @var list<array<string,mixed>> */
    public array $auditedClasses = [];

    /** @var array<string,list<string>> */
    public array $enumCases = [];

    /** @var list<string> */
    public array $requiresReason = [];

    public function __construct(private Printer $printer, private string $apiRoot) {}

    public function enterNode(Node $n)
    {
        $line = $n->getStartLine();
        $loc = rel($this->apiRoot, $this->file).':'.$line;

        if ($n instanceof Node\Expr\ClassConstFetch
            && isAuditActionName($n->class)
            && $n->name instanceof Node\Identifier
            && $n->name->toString() !== 'class') {
            // `node` keeps the AST node alive: spl_object_id() values are reused once an
            // object is freed, which made the call-site/reference match below depend on
            // memory layout (the doc changed with the number of files scanned).
            $this->refs[] = ['file' => $this->file, 'line' => $line, 'case' => $n->name->toString(), 'id' => spl_object_id($n), 'node' => $n];
        }

        if ($n instanceof Node\Expr\StaticCall
            && isAuditActionName($n->class)
            && $n->name instanceof Node\Identifier
            && in_array($n->name->toString(), ['from', 'tryFrom'], true)) {
            $this->dynamicResolvers[] = [
                'loc' => $loc,
                'fn' => $n->name->toString(),
                'expr' => $this->printer->prettyPrintExpr($n),
            ];
        }

        if (isAuditLogCall($n, $this->printer)) {
            $map = mapArgs($n->args);
            $actionNode = $map['action'] ?? null;
            $case = null;
            $actionExpr = $actionNode ? $this->printer->prettyPrintExpr($actionNode) : '(missing)';
            if ($actionNode instanceof Node\Expr\ClassConstFetch && isAuditActionName($actionNode->class) && $actionNode->name instanceof Node\Identifier) {
                $case = $actionNode->name->toString();
            }
            $call = [
                'loc' => $loc,
                'case' => $case,
                'actionId' => $actionNode ? spl_object_id($actionNode) : 0,
                'actionNode' => $actionNode,
                'actionExpr' => $actionExpr,
                'has' => [
                    'reason' => isset($map['reason']),
                    'actor' => isset($map['actor']),
                    'subject' => isset($map['subject']),
                ],
                'payload' => [],
                'spread' => isset($map['__spread']),
            ];
            foreach (PAYLOAD_PARAMS as $p) {
                if (isset($map[$p])) {
                    $call['payload'][$p] = describePayload($map[$p], $this->printer);
                }
            }
            $this->calls[] = $call;
        }

        if ($n instanceof Node\Stmt\Enum_ && $n->namespacedName?->toString() === 'App\\Modules\\Audit\\Enums\\AuditAction') {
            foreach ($n->stmts as $s) {
                if ($s instanceof Node\Stmt\EnumCase) {
                    $v = $s->expr instanceof Node\Scalar\String_ ? $s->expr->value : '(non-literal)';
                    $this->enumCases[$s->name->toString()] = [$v];
                }
                if ($s instanceof Node\Stmt\ClassMethod && $s->name->toString() === 'requiresReason') {
                    $match = (new NodeFinder)->findFirstInstanceOf($s->stmts ?? [], Node\Expr\Match_::class);
                    if ($match) {
                        foreach ($match->arms as $arm) {
                            $isTrue = $arm->body instanceof Node\Expr\ConstFetch && strtolower($arm->body->name->toString()) === 'true';
                            foreach ($arm->conds ?? [] as $c) {
                                if ($isTrue && $c instanceof Node\Expr\ClassConstFetch && $c->name instanceof Node\Identifier) {
                                    $this->requiresReason[] = $c->name->toString();
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($n instanceof Node\Stmt\Class_ && $n->namespacedName !== null) {
            $uses = false;
            foreach ($n->stmts as $s) {
                if ($s instanceof Node\Stmt\TraitUse) {
                    foreach ($s->traits as $t) {
                        if ($t->getLast() === 'Audited') {
                            $uses = true;
                        }
                    }
                }
            }
            if ($uses) {
                $allow = [];
                $customAction = [];
                foreach ($n->stmts as $s) {
                    if ($s instanceof Node\Stmt\ClassMethod && $s->name->toString() === 'auditableAllowlist') {
                        $ret = (new NodeFinder)->findFirstInstanceOf($s->stmts ?? [], Node\Stmt\Return_::class);
                        if ($ret && $ret->expr instanceof Node\Expr\Array_) {
                            foreach ($ret->expr->items as $it) {
                                $allow[] = $it->value instanceof Node\Scalar\String_ ? $it->value->value : '(dynamic) '.$this->printer->prettyPrintExpr($it->value);
                            }
                        } else {
                            $allow[] = '(not a literal array — needs a manual read)';
                        }
                    }
                    if ($s instanceof Node\Stmt\ClassMethod && $s->name->toString() === 'auditAction') {
                        foreach ((new NodeFinder)->findInstanceOf($s->stmts ?? [], Node\Expr\ClassConstFetch::class) as $c) {
                            if (isAuditActionName($c->class) && $c->name instanceof Node\Identifier) {
                                $customAction[] = $c->name->toString();
                            }
                        }
                    }
                }
                $this->auditedClasses[] = [
                    'class' => $n->namespacedName->toString(),
                    'file' => $this->file,
                    'line' => $n->getStartLine(),
                    'allowlist' => $allow,
                    'customAction' => array_values(array_unique($customAction)),
                ];
            }
        }

        return null;
    }
}

// ── scan ────────────────────────────────────────────────────────────────────
$collector = new Collector($printer, $apiRoot);
$parseErrors = [];
$files = phpFiles($apiRoot.'/app');

foreach ($files as $file) {
    try {
        $ast = $parser->parse((string) file_get_contents($file));
    } catch (Throwable $e) {
        $parseErrors[] = rel($apiRoot, $file).': '.$e->getMessage();

        continue;
    }
    $collector->file = $file;
    $t = new NodeTraverser;
    $t->addVisitor(new NameResolver);
    $t->addVisitor($collector);
    $t->traverse($ast ?? []);
}

$cases = $collector->enumCases;
ksort($cases);
$requiresReason = array_flip(array_unique($collector->requiresReason));
$enumFile = 'apps/api/app/Modules/Audit/Enums/AuditAction.php';

$callIds = [];
foreach ($collector->calls as $c) {
    if ($c['actionId'] !== 0) {
        $callIds[$c['actionId']] = true;
    }
}

// per-case aggregation
$per = [];
foreach ($cases as $name => [$value]) {
    $per[$name] = ['value' => $value, 'direct' => [], 'other' => [], 'meta' => [], 'before' => [], 'after' => [], 'dyn' => []];
}
foreach ($collector->refs as $r) {
    $loc = rel($apiRoot, $r['file']).':'.$r['line'];
    if (rel($apiRoot, $r['file']) === $enumFile) {
        continue;
    }
    if (isset($per[$r['case']]) && ! isset($callIds[$r['id']])) {
        $per[$r['case']]['other'][] = $loc;
    }
}
$unknownCaseCalls = [];
$dynamicActionCalls = [];
foreach ($collector->calls as $c) {
    if ($c['case'] === null) {
        $dynamicActionCalls[] = $c;

        continue;
    }
    if (! isset($per[$c['case']])) {
        $unknownCaseCalls[] = $c;

        continue;
    }
    $p = &$per[$c['case']];
    $p['direct'][] = $c['loc'];
    foreach (['metadata' => 'meta', 'before' => 'before', 'after' => 'after'] as $param => $slot) {
        if (isset($c['payload'][$param])) {
            $p[$slot] = array_merge($p[$slot], $c['payload'][$param]['keys']);
            foreach ($c['payload'][$param]['dynamic'] as $d) {
                $p['dyn'][] = $param.': '.$d.' @ '.$c['loc'];
            }
        }
    }
    unset($p);
}

// ── machine-readable mode (used by the AuditPayloadKeysGuardTest spec) ───────
// `--json` prints only the literal payload keys and the non-literal payload
// parts, so a spec can fail CI when a new key or a new dynamic payload appears
// that the erasure registry has not decided. The Markdown output below is
// unchanged by this flag.
if (in_array('--json', $argv ?? [], true)) {
    $jsonKeys = [];
    $jsonDynamic = [];
    foreach ($collector->calls as $c) {
        foreach ($c['payload'] as $param => $d) {
            foreach ($d['keys'] as $k) {
                $jsonKeys[$k][$param] = true;
            }
            if ($d['dynamic'] !== []) {
                $jsonDynamic[(string) preg_replace('/:\d+$/', '', $c['loc']).'|'.$param] = true;
            }
        }
    }
    foreach ($collector->auditedClasses as $m) {
        foreach ($m['allowlist'] as $k) {
            $jsonKeys[$k]['audited allowlist'] = true;
        }
    }
    ksort($jsonKeys);
    ksort($jsonDynamic);
    echo json_encode([
        'keys' => array_map(static fn (array $by): array => array_keys($by), $jsonKeys),
        'dynamic' => array_keys($jsonDynamic),
        'calls' => count($collector->calls),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}

// ── render ──────────────────────────────────────────────────────────────────
$sha = trim((string) shell_exec('git -C '.escapeshellarg($apiRoot).' rev-parse HEAD 2>/dev/null')) ?: 'unknown';
$dirty = trim((string) shell_exec('git -C '.escapeshellarg($apiRoot).' status --porcelain -- app tools/enumerate-audit-actions.php 2>/dev/null')) !== '' ? ' (working tree dirty under app/)' : '';

$out = [];
$o = static function (string $s = '') use (&$out): void {
    $out[] = $s;
};
$list = static fn (array $a): string => $a === [] ? '—' : implode(', ', array_map(static fn ($x) => '`'.$x.'`', $a));
$locs = static fn (array $a): string => $a === [] ? '—' : implode('<br>', array_map(static fn ($x) => '`'.$x.'`', $a));

$o('# AH-104a Stage 0 — audit action and metadata enumeration');
$o();
$o('> **Generated file — do not edit by hand.** Regenerate with:');
$o('>');
$o('> ```');
$o('> cd apps/api && php tools/enumerate-audit-actions.php > ../../docs/reviews/account-deletion-audit-enumeration.md');
$o('> ```');
$o('>');
$o("> Source commit: `{$sha}`{$dirty}. Static analysis only (nikic/php-parser); no Laravel boot, no DB, no application code executed. Scanned ".count($files).' PHP files under `apps/api/app`.');
$o();

$withDirect = array_filter($per, fn ($p) => $p['direct'] !== []);
$onlyIndirect = array_filter($per, fn ($p) => $p['direct'] === [] && $p['other'] !== []);
$unreferenced = array_filter($per, fn ($p) => $p['direct'] === [] && $p['other'] === []);

$o('## 1. Summary');
$o();
$o('| Measure | Count |');
$o('|---|---:|');
$o('| `AuditAction` cases | '.count($cases).' |');
$o('| …with `requiresReason() === true` | '.count($requiresReason).' |');
$o('| Audit log call sites found | '.count($collector->calls).' |');
$o('| …with a literal `AuditAction::X` action | '.(count($collector->calls) - count($dynamicActionCalls)).' |');
$o('| …with a dynamic action (needs a manual read) | '.count($dynamicActionCalls).' |');
$o('| Cases with a direct call site | '.count($withDirect).' |');
$o('| Cases referenced only indirectly (passed through a helper, a map, or a listener) | '.count($onlyIndirect).' |');
$o('| Cases with no reference in `app/` outside the enum (dead, unless the `Audited` trait derives them) | '.count($unreferenced).' |');
$o('| `AuditAction::from/tryFrom` dynamic resolvers | '.count($collector->dynamicResolvers).' |');
$o('| Models using the `Audited` trait | '.count($collector->auditedClasses).' |');
$o('| Parse errors | '.count($parseErrors).' |');
$o();

$o('## 2. Every case');
$o();
$o('"Direct" = the case is the literal `action:` argument of an audit log call. "Other refs" = the case appears elsewhere (helper argument, `match` map, listener filter, query). A case with only "other refs" is emitted indirectly; the emitting call is in section 3 (dynamic action) or section 4 (Audited trait). Key lists are the literal top-level keys only; anything non-literal is in the last column and needs a manual read.');
$o();
$o('| Case | Value | Reason req. | Direct sites | Other refs | `metadata` keys | `before` keys | `after` keys | Dynamic payload parts |');
$o('|---|---|:--:|---|---|---|---|---|---|');
foreach ($per as $name => $p) {
    $meta = array_values(array_unique($p['meta']));
    sort($meta);
    $bef = array_values(array_unique($p['before']));
    sort($bef);
    $aft = array_values(array_unique($p['after']));
    sort($aft);
    $dyn = array_values(array_unique($p['dyn']));
    $o('| `'.$name.'` | `'.$p['value'].'` | '.(isset($requiresReason[$name]) ? 'yes' : '').' | '.$locs($p['direct']).' | '.$locs($p['other']).' | '.$list($meta).' | '.$list($bef).' | '.$list($aft).' | '.($dyn === [] ? '—' : implode('<br>', array_map(static fn ($x) => '`'.str_replace('|', '\\|', $x).'`', $dyn))).' |');
}
$o();

$o('## 3. Call sites needing a manual read');
$o();
$o('### 3.1 Dynamic action (the case is chosen at runtime)');
$o();
if ($dynamicActionCalls === []) {
    $o('None.');
} else {
    $o('| Site | Action expression | `metadata` keys | Dynamic payload parts | reason? | actor? | subject? |');
    $o('|---|---|---|---|:--:|:--:|:--:|');
    foreach ($dynamicActionCalls as $c) {
        $mk = $c['payload']['metadata']['keys'] ?? [];
        $dd = [];
        foreach ($c['payload'] as $param => $d) {
            foreach ($d['dynamic'] as $x) {
                $dd[] = $param.': '.$x;
            }
        }
        $o('| `'.$c['loc'].'` | `'.str_replace('|', '\\|', $c['actionExpr']).'` | '.$list($mk).' | '.($dd === [] ? '—' : implode('<br>', array_map(static fn ($x) => '`'.str_replace('|', '\\|', $x).'`', $dd))).' | '.($c['has']['reason'] ? 'y' : '').' | '.($c['has']['actor'] ? 'y' : '').' | '.($c['has']['subject'] ? 'y' : '').' |');
    }
}
$o();
$o('### 3.2 Direct calls whose payload has a non-literal part');
$o();
$rows = [];
foreach ($collector->calls as $c) {
    if ($c['case'] === null) {
        continue;
    }
    foreach ($c['payload'] as $param => $d) {
        foreach ($d['dynamic'] as $x) {
            $rows[] = '| `'.$c['loc'].'` | `'.$c['case'].'` | `'.$param.'` | `'.str_replace('|', '\\|', $x).'` |';
        }
    }
}
sort($rows);
if ($rows === []) {
    $o('None.');
} else {
    $o('| Site | Case | Param | Non-literal expression |');
    $o('|---|---|---|---|');
    foreach ($rows as $r) {
        $o($r);
    }
}
$o();
$o('### 3.3 `AuditAction::from` / `tryFrom` resolvers');
$o();
if ($collector->dynamicResolvers === []) {
    $o('None.');
} else {
    $o('| Site | Call |');
    $o('|---|---|');
    foreach ($collector->dynamicResolvers as $d) {
        $o('| `'.$d['loc'].'` | `'.str_replace('|', '\\|', $d['expr']).'` |');
    }
}
$o();
$o('### 3.4 Calls naming a case the enum does not declare');
$o();
if ($unknownCaseCalls === []) {
    $o('None.');
} else {
    foreach ($unknownCaseCalls as $c) {
        $o('- `'.$c['loc'].'` — `'.$c['case'].'`');
    }
}
$o();

$o('## 4. Models using the `Audited` trait');
$o();
$o('The trait emits `created` / `updated` / `deleted` rows from model events: `after` (allowlisted attributes) on create, `before` + `after` (changed allowlisted attributes) on update, `before` on delete. The action comes from `auditAction($event)`: the trait default is `AuditAction::from(lower(class_basename).".".event)`; a model may override it.');
$o();
$valueToCase = [];
foreach ($cases as $name => [$v]) {
    $valueToCase[$v] = $name;
}
usort($collector->auditedClasses, fn ($a, $b) => strcmp($a['class'], $b['class']));
$o('| Model | Allowlist (snapshot keys) | `auditAction()` | Derived/declared actions exist in the enum? |');
$o('|---|---|---|---|');
foreach ($collector->auditedClasses as $m) {
    $base = strtolower(substr(strrchr($m['class'], '\\'), 1));
    if ($m['customAction'] !== []) {
        $act = 'overridden → '.$list($m['customAction']);
        $exist = 'yes (literal cases)';
    } else {
        $derived = [];
        foreach (['created', 'updated', 'deleted'] as $ev) {
            $derived[] = $base.'.'.$ev.(isset($valueToCase[$base.'.'.$ev]) ? ' ✓' : ' ✗ MISSING');
        }
        $act = 'trait default → '.implode(', ', array_map(static fn ($x) => '`'.$x.'`', $derived));
        $exist = in_array(true, array_map(static fn ($x) => str_contains($x, 'MISSING'), $derived), true) ? 'NO — a missing case throws on that model event' : 'yes';
    }
    $o('| `'.$m['class'].'`<br>`'.rel($apiRoot, $m['file']).':'.$m['line'].'` | '.$list($m['allowlist']).' | '.$act.' | '.$exist.' |');
}
$o();

$o('## 5. Union of literal payload keys');
$o();
$o('"In §4.1 scrub list" = the key is already named by the PII map. "PII-name heuristic" = the key name looks like it could carry personal data and is **not** on the list — each of these needs a ruling or an explicit "not personal".');
$o();
$keyUse = [];
foreach ($collector->calls as $c) {
    foreach ($c['payload'] as $param => $d) {
        foreach ($d['keys'] as $k) {
            $keyUse[$k][$param][] = ($c['case'] ?? '(dynamic)').' @ '.$c['loc'];
        }
    }
}
foreach ($collector->auditedClasses as $m) {
    foreach ($m['allowlist'] as $k) {
        $keyUse[$k]['audited allowlist'][] = $m['class'];
    }
}
ksort($keyUse);
$o('| Key | In §4.1 scrub list | PII-name heuristic | Used in | Sites |');
$o('|---|:--:|:--:|---|---:|');
foreach ($keyUse as $k => $by) {
    $n = 0;
    foreach ($by as $sites) {
        $n += count($sites);
    }
    $o('| `'.$k.'` | '.(in_array($k, SCRUB_LIST, true) ? 'yes' : '').' | '.(! in_array($k, SCRUB_LIST, true) && preg_match(PII_HEURISTIC, $k) ? '**review**' : '').' | '.implode(', ', array_map(static fn ($x) => '`'.$x.'`', array_keys($by))).' | '.$n.' |');
}
$o();

if ($parseErrors !== []) {
    $o('## 6. Parse errors');
    $o();
    foreach ($parseErrors as $e) {
        $o('- `'.$e.'`');
    }
    $o();
}

echo implode("\n", $out)."\n";
