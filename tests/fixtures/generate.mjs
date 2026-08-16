#!/usr/bin/env node
/**
 * Regenerate the cross-language fixtures.
 *
 *   node test/fixtures/generate.mjs
 *
 * The PHP agent writes sketches that the JavaScript collector decodes, and
 * normalizes SQL that has to reduce to the same shape the JavaScript agent
 * produces — because the collector hashes that shape into an operation's
 * identity, and two spellings would split one operation's history in half.
 *
 * Neither of those is checkable by a PHP test on its own: a bug that is
 * consistent between the PHP writer and a PHP reader passes every roundtrip
 * test there is. So the expectations come from the JavaScript implementation
 * itself, written down here, and tests/SketchTest.php and tests/SqlTest.php
 * assert against them.
 *
 * Run this whenever packages/core/src/{sketch,sql}.js changes. A diff in the
 * output is a wire-format change, and it means every agent has to move
 * together.
 */

import { writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { DDSketch } from '../../../core/src/sketch.js'
import { normalizeSql, sqlOperationName, templatePath } from '../../../core/src/sql.js'

const here = dirname(fileURLToPath(import.meta.url))

const sketchCases = [
  { name: 'empty', values: [] },
  { name: 'single', values: [1] },
  { name: 'small integers', values: [1, 2, 3, 4, 5] },
  { name: 'durations ms', values: [0.4, 1.2, 3.7, 12.9, 41.3, 180.5, 940.1, 1_204.8] },
  { name: 'zeros and negatives', values: [0, 0, 0, -1, -0.5, 2] },
  { name: 'row counts', values: [30, 30, 30, 30_000, 30_000] },
  { name: 'wide range', values: [1e-6, 1e-3, 1, 1e3, 1e6, 1e9] },
  {
    name: 'many buckets',
    values: Array.from({ length: 500 }, (_, i) => (i + 1) * 1.37),
  },
]

const sketches = sketchCases.map(({ name, values }) => {
  const sketch = new DDSketch()
  for (const v of values) sketch.add(v)
  return {
    name,
    values,
    base64: sketch.toBase64(),
    count: sketch.count,
    sum: sketch.sum,
    p50: sketch.quantile(0.5),
    p95: sketch.quantile(0.95),
  }
})

const sqlCases = [
  `select o.id, o.total_cents from orders o where o.user_id = $1 order by o.created_at desc limit 30`,
  `SELECT "orders".* FROM "orders" WHERE "orders"."user_id" = $1 AND "orders"."status" = 'paid'`,
  `select * from users where email = 'alice@example.com' and id = 42`,
  `select * from items where order_id in (1, 2, 3, 4, 5)`,
  `insert into users (email, name) values ('a@b.c', 'A'), ('d@e.f', 'D')`,
  `update accounts set balance = balance - 10.50 where id = 7 -- transfer\n`,
  `/* app:orders */ select count(*) from orders where created_at > now() - interval '7 days'`,
  `with recent as (select id from orders where created_at > $1) select * from recent join order_items i on i.order_id = recent.id`,
  `delete from sessions where expires_at < $1`,
  `select 0x1f, 1e-9, col2, "col3" from t`,
  `select * from "public"."order_items" where order_id = any($1)`,
  `SELECT a.attname FROM pg_attribute a WHERE a.attrelid = '"orders"'::regclass`,
]

const sql = sqlCases.map((raw) => {
  const normalized = normalizeSql(raw)
  return { raw, normalized, name: sqlOperationName(normalized) }
})

// MySQL is not a variant spelling of the same thing: `"x"` is an identifier in
// one dialect and a string literal in the other, and reading MySQL with the
// Postgres rules transmits the literal. Rails apps run on both, so both are
// pinned here.
const mysqlCases = [
  'SELECT `orders`.* FROM `orders` WHERE `orders`.`user_id` = ? LIMIT 30',
  "select * from users where email = \"alice@example.com\" and name = 'a\\'b'",
  'insert into `order_items` (`order_id`, `qty`) values (1, 2), (3, 4)',
  'select count(*) from orders # inline comment\n',
  'select a$b from t where id = 5'
]

const mysql = mysqlCases.map((raw) => {
  const normalized = normalizeSql(raw, 'mysql')
  return { raw, normalized, name: sqlOperationName(normalized) }
})

const paths = [
  '/',
  '/users/42',
  '/users/42/orders',
  '/orders/9f8a1e2c-3b4d-4e5f-8a9b-0c1d2e3f4a5b',
  '/stops/es:renfe-cer:stop:71801',
  '/assets/application-a1b2c3d4e5f6a7b8c9d0.js',
  '/products?category=tools',
  '/api/v1/reports/2024-01-01',
].map((path) => ({ path, templated: templatePath(path) }))

writeFileSync(
  join(here, 'wire.json'),
  `${JSON.stringify({ sketches, sql, mysql, paths }, null, 2)}\n`
)
console.log(
  `wrote ${sketches.length} sketch cases, ${sql.length} sql cases, ` +
    `${mysql.length} mysql cases, ${paths.length} paths`
)
