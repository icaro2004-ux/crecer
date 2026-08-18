<?php
/**
 * pl_pdf.php — convierte PL-xprize.csv en una hoja imprimible.
 *
 *   php evidencia/pl_pdf.php          → escribe evidencia/PL-xprize.html
 *
 * Existe porque Devpost exige el P&L como **archivo** (pdf/png/jpg), no como
 * texto pegado, y la plantilla oficial es una Google Sheet. En vez de copiar
 * numeros a mano a la hoja y exportarla, se dibuja aqui con la misma estructura
 * (COGS / SG&A / Tokens por mes) y se imprime a PDF con Chrome.
 */

declare(strict_types=1);

$dir = __DIR__;
$csv = "$dir/PL-xprize.csv";
if (!is_file($csv)) { fwrite(STDERR, "Falta PL-xprize.csv — corre antes: php evidencia/pl.php\n"); exit(1); }

$filas = [];
$f = fopen($csv, 'r');
while (($r = fgetcsv($f)) !== false) $filas[] = $r;
fclose($f);

$esTitulo  = fn(string $s) => in_array(trim($s), ['REVENUE','EXPENSES','COGS','SG&A','Other Expenses'], true);
$esTotal   = fn(string $s) => in_array(trim($s), ['TOTAL REVENUE','TOTAL EXPENSES','PROFIT (LOSS)'], true);
$dinero    = fn($v) => ($v === '' || $v === null) ? '' : '$' . number_format((float)$v, 2);

ob_start(); ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Crecer — P&amp;L</title>
<style>
  @page { size: letter portrait; margin: 0.7in; }
  *{box-sizing:border-box}
  body{font:13px/1.45 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#231F20;margin:0}
  h1{font-size:19px;margin:0 0 2px}
  .sub{color:#6E6A67;font-size:12px;margin:0 0 3px}
  .meta{color:#6E6A67;font-size:11px;margin:0 0 16px}
  table{width:100%;border-collapse:collapse;margin-top:6px}
  th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#6E6A67;
     text-align:right;padding:6px 8px;border-bottom:1.5px solid #231F20}
  th.l{text-align:left}
  td{padding:5px 8px;text-align:right;border-bottom:1px solid #EFECE8;font-variant-numeric:tabular-nums}
  td.l{text-align:left}
  tr.t td{font-weight:700;background:#F5F2EE;border-bottom:1px solid #E4DFD9}
  tr.tot td{font-weight:700;border-top:1.5px solid #231F20;border-bottom:1.5px solid #231F20}
  tr.sp td{border:0;height:8px;padding:0}
  .ind{padding-left:24px}
  .notas{margin-top:20px;font-size:11px;color:#4A434F;line-height:1.6}
  .notas b{color:#231F20}
  .notas ul{margin:5px 0 0;padding-left:16px}
</style></head><body>

<h1>Crecer — Profit &amp; Loss</h1>
<p class="sub">Build with Gemini XPRIZE · hackathon period May 19 – August 17, 2026 · USD</p>
<p class="meta">Cash basis. Entrant: Jes&uacute;s Manuel P&eacute;rez Rivera (individual). Every expense line is backed by an attached provider receipt.</p>

<table>
<?php foreach ($filas as $i => $r):
    $et = (string)($r[0] ?? '');
    if ($i === 0): ?>
  <tr><th class="l"><?= htmlspecialchars($et) ?></th>
      <?php for ($c=1; $c<=5; $c++): ?><th><?= htmlspecialchars((string)($r[$c] ?? '')) ?></th><?php endfor; ?></tr>
<?php   continue; endif;
    if (trim($et) === '' ): ?>
  <tr class="sp"><td colspan="6"></td></tr>
<?php   continue; endif;
    $cls = $esTitulo($et) ? 't' : ($esTotal($et) ? 'tot' : '');
    $ind = (!$esTitulo($et) && !$esTotal($et)) ? ' ind' : ''; ?>
  <tr class="<?= $cls ?>"><td class="l<?= $ind ?>"><?= htmlspecialchars($et) ?></td>
      <?php for ($c=1; $c<=5; $c++): ?><td><?= $dinero($r[$c] ?? '') ?></td><?php endfor; ?></tr>
<?php endforeach; ?>
</table>

<div class="notas">
  <b>Notes</b>
  <ul>
    <li><b>Revenue is $0.</b> Stripe runs in live mode at $39/month and a live checkout session opens, but no payment was ever completed. No founder payment was run through the system to manufacture traction.</li>
    <li><b>SG&amp;A is research and development.</b> The $334.50 is Claude Max, the AI tooling used to write the software, billed at $111.50/month including Puerto Rico sales tax on May 23, June 23 and July 23. The August 23 charge falls after the deadline and is excluded. The official template has no R&amp;D line, so it sits under SG&amp;A.</li>
    <li><b>COGS is the cost of running the service in production</b> — Google Cloud Gemini API $25.15, OpenAI image-generation API credits $45.10, Twilio $0.59, Shotstack video rendering $39.00. Roughly $37/month to operate.</li>
    <li><b>Marketing and customer acquisition spend: $0.00.</b></li>
    <li><b>Pre-hackathon resource, disclosed:</b> Hostinger Business Web Hosting, $59.88 paid April 25, 2026 for an annual plan running to June 2027. Paid before the period opened, so under cash basis it is excluded above. Prorated, roughly $13 is attributable to the window.</li>
    <li>Some receipts are billed to <b>Manuel Rivers</b>, a pseudonym of the entrant &mdash; same person, same email and address as the other receipts.</li>
  </ul>
</div>

<div style="page-break-before:always"></div>
<h1 style="margin-top:0">Receipt index</h1>
<p class="sub">Every expense line above, traceable to a provider document. Cash basis: the date is the date the money left.</p>
<table>
  <tr><th class="l">Paid</th><th class="l">Provider</th><th class="l">Document</th><th class="l">Covers</th><th>Amount</th></tr>
  <tr><td class="l">May 23, 2026</td><td class="l">Anthropic</td><td class="l">MC7YRZYB-0006 &middot; rcpt 2134-8842-0486</td><td class="l">Claude Max 5x, May 23 &ndash; Jun 23 (incl. 11.5% PR tax)</td><td>$111.50</td></tr>
  <tr><td class="l">Jun 23, 2026</td><td class="l">Anthropic</td><td class="l">MC7YRZYB-0007 &middot; rcpt 2431-6302-5643</td><td class="l">Claude Max 5x, Jun 23 &ndash; Jul 23</td><td>$111.50</td></tr>
  <tr><td class="l">Jul 21, 2026</td><td class="l">OpenAI</td><td class="l">6SFNXD4M-0002 &middot; rcpt 2236-2709-0831</td><td class="l">API credit, image generation (prepaid)</td><td>$30.00</td></tr>
  <tr><td class="l">Jul 23, 2026</td><td class="l">Anthropic</td><td class="l">MC7YRZYB-0008 &middot; rcpt 2132-5317-6440</td><td class="l">Claude Max 5x, Jul 23 &ndash; Aug 23</td><td>$111.50</td></tr>
  <tr><td class="l">Jul 31, 2026</td><td class="l">Twilio</td><td class="l">NWWWYO-2026-07</td><td class="l">Verify SMS, Jul 1 &ndash; Jul 31</td><td>$0.59</td></tr>
  <tr><td class="l">Aug 10, 2026</td><td class="l">OpenAI</td><td class="l">6SFNXD4M-0004 &middot; rcpt 2689-9533-3895</td><td class="l">API credit, auto-reload</td><td>$15.10</td></tr>
  <tr><td class="l">Aug 15, 2026</td><td class="l">Shotstack</td><td class="l">IDXR8QYP-0001</td><td class="l">Video rendering, 200 credits (first paid charge)</td><td>$39.00</td></tr>
  <tr><td class="l">Jun&ndash;Aug 2026</td><td class="l">Google Cloud</td><td class="l">Billing report, 1 Jun &ndash; 31 Aug</td><td class="l">Gemini API &mdash; 3,886 recorded calls</td><td>$25.15</td></tr>
  <tr class="tot"><td class="l" colspan="4">TOTAL &mdash; inside the hackathon window</td><td>$444.34</td></tr>
  <tr class="sp"><td colspan="5"></td></tr>
  <tr><td class="l">Apr 25, 2026</td><td class="l">Hostinger</td><td class="l">HCY-24490180 (paid)</td><td class="l">Annual hosting, Apr 2026 &ndash; Jun 2027 &mdash; <b>pre-hackathon, excluded above</b></td><td>$59.88</td></tr>
</table>
<div class="notas">
  <b>How to audit this.</b> Each row names the provider&rsquo;s own invoice or receipt number, so any line can be
  matched against the original document on request. The Anthropic receipts are billed to <b>Manuel Rivers</b>,
  a pseudonym of the entrant, Jes&uacute;s Manuel P&eacute;rez Rivera &mdash; same email and same address as the
  OpenAI, Shotstack and Hostinger receipts. Anthropic&rsquo;s next charge fell on August 23, after the
  submission deadline, and is deliberately excluded.
</div>
</body></html>
<?php
file_put_contents("$dir/PL-xprize.html", ob_get_clean());
echo "evidencia/PL-xprize.html listo\n";
