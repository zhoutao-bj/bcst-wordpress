<?php
// Run with PHP 7.4+: php tests/translation-fragments-test.php
// No WordPress database, network requests or model fees.
define('ABSPATH',__DIR__);
function add_action(...$args) {}
function add_filter(...$args) {}
function esc_html($value) { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8',false); }
$shortcode_tags=array('bcst_inquiry'=>true);
$requests=array();
function bcst_bailian_translate($text,$source,$target) {
    global $requests;$requests[]=$text;
    return array('text'=>$text,'tokens'=>0);
}
require dirname(__DIR__).'/wp-content/plugins/bcst-core/batch-translation.php';
function verify($condition,$label) { if (!$condition) throw new Exception($label); }
function render_fixture($source) {
    global $requests;$requests=array();$parts=bcst_tx_parts($source);$task=array();
    while (bcst_tx_translate_part($parts,'en','mn',$task)) {}
    return implode('',array_column($parts,'text'));
}
$source='<p>Visitors&#8217; IP addresses and <strong>browser details</strong> are collected. Read <a href="https://example.com/?a=1&amp;b=2" title="Privacy details">our policy</a>.</p>';
$result=render_fixture($source);
verify(strpos($result,'<strong>browser details</strong>')!==false,'Inline tags preserved');
verify(strpos($result,'href="https://example.com/?a=1&amp;b=2"')!==false,'URL preserved');
verify(count($requests)===2,'One attribute and one full paragraph request');
verify(strpos($requests[1],'Visitors’ IP addresses and')!==false,'Entity decoded within sentence');
verify(strpos($requests[1],'are collected. Read')!==false,'Inline markup does not split paragraph');
verify(render_fixture('<pre>keep &amp; [code] exactly</pre><script>var x="<b>";</script>')==='<pre>keep &amp; [code] exactly</pre><script>var x="<b>";</script>','Raw blocks unchanged');
verify(render_fixture('[bcst_inquiry]')==='[bcst_inquiry]','Registered shortcode unchanged');
render_fixture('<p>[Replace this sentence before launch.]</p>');
verify(count($requests)===1,'Ordinary bracketed prose translated');
$result=render_fixture('<p>Email sales@example.com, use BCST-100 and {{name}}.</p>');
verify(strpos($result,'sales@example.com')!==false && strpos($result,'BCST-100')!==false && strpos($result,'{{name}}')!==false,'Protected values restored');
verify(count($requests)===1,'Protected values do not break sentence');
verify(render_fixture('<img alt="&quot;" src="example.png">')==='<img alt="&quot;" src="example.png">','Attribute escaping retained');
$parts=bcst_tx_parts('Read <strong>this policy</strong>.');$part=$parts[0];
foreach (array(str_replace(array_keys($part['keep']),'',$part['source']),$part['source'].'__BCST_aaaaaaaaaaaa_99__') as $invalid) {
    $caught=false;try {bcst_tx_restore_fragment($part,$invalid);}catch(Exception $e){$caught=true;}
    verify($caught,'Missing or invented tokens rejected');
}
render_fixture(str_repeat('A complete sentence with context. ',250));
verify(count($requests)>1 && max(array_map('strlen',$requests))<=5500,'Long paragraphs bounded at sentence boundaries');
echo "Translation fragment fixtures passed.\n";
