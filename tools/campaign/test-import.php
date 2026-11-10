<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCP { interface ITempManager {public function getTemporaryFile(string $postfix=''):string;} }
namespace {
 if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
 require __DIR__.'/../../lib/Service/CampaignDefinition.php';require __DIR__.'/../../lib/Service/CampaignRecipientImport.php';
 $temp=new class implements \OCP\ITempManager{public function getTemporaryFile(string $postfix=''):string{return tempnam(sys_get_temp_dir(),'campaign-import-qa-');}};
 $import=new \OCA\Libresign\Service\CampaignRecipientImport($temp);$checks=0;
 $check=static function(bool $ok,string $label)use(&$checks){if(!$ok)throw new \RuntimeException($label);$checks++;echo 'PASS: '.$label.PHP_EOL;};
 $reject=static function(callable $fn,string $label)use($check){try{$fn();}catch(\InvalidArgumentException){$check(true,$label);return;}$check(false,$label);};
 $csv=static fn(string $text)=>$import->parse(base64_encode($text),'recipients.csv');
 $check($csv("Name,Email\nAda,ada@example.test")['rows'][1]['cells']===['Ada','ada@example.test'],'CSV names and emails retained');
 $check($csv("\xEF\xBB\xBFEmail;Name\r\na@example.test;Ada")['rows'][0]['cells']===['Email','Name'],'BOM and semicolon CSV supported');
 $check($csv("Name\tEmail\nAda\ta@example.test")['rows'][1]['cells'][1]==='a@example.test','tab-delimited CSV supported');
 $check($csv("Name,Email\n\"Ada, A\",a@example.test")['rows'][1]['cells'][0]==='Ada, A','quoted commas retained');
 $check($csv("Name,Email\n\"Ada\nA\",a@example.test")['rows'][1]['cells'][0]==="Ada\nA",'quoted line breaks parsed as one row');
 $check(count($csv("Name,Email\n\nAda,a@example.test\n")['rows'])===2,'blank rows ignored');
 $reject(fn()=>$import->parse('!bad-base64','a.csv'),'invalid base64 rejected');
 $reject(fn()=>$import->parse(base64_encode('data'),'a.xls'),'legacy XLS rejected with conversion guidance');
 $reject(fn()=>$csv("Name,Email\nA,\xFF"),'invalid text encoding rejected');
 $reject(fn()=>$csv(str_repeat("A,a@example.test\n",2002)),'recipient row cap enforced');
 $reject(fn()=>$csv(implode(',',range(1,31))),'column cap enforced');
 $reject(fn()=>$csv(''),'empty file rejected');
 $reject(fn()=>$import->parse(base64_encode('not a zip'),'a.xlsx'),'damaged XLSX rejected');
 $make=static function(array $entries)use($temp):string{$path=$temp->getTemporaryFile();$zip=new \ZipArchive();$zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE);foreach($entries as $name=>$content)$zip->addFromString($name,$content);$zip->close();try{return base64_encode(file_get_contents($path));}finally{unlink($path);}};
 $entries=['xl/workbook.xml'=>'<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="People" r:id="rId1"/><sheet name="Other" r:id="rId2"/></sheets></workbook>',
 'xl/_rels/workbook.xml.rels'=>'<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="/xl/worksheets/sheet2.xml"/></Relationships>',
 'xl/sharedStrings.xml'=>'<sst><si><t>Email</t></si><si><t>Name</t></si><si><r><t>Ada </t></r><r><t>Example</t></r></si></sst>',
 'xl/worksheets/sheet1.xml'=>'<worksheet><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="7"><c r="A7" t="inlineStr"><is><t>ada@example.test</t></is></c><c r="B7" t="s"><v>2</v></c></row></sheetData></worksheet>',
 'xl/worksheets/sheet2.xml'=>'<worksheet><sheetData><row r="3"><c r="B3"><f>HYPERLINK("https://example.test")</f><v>ignored</v></c><c r="C3" t="inlineStr"><is><t>Text</t></is></c></row></sheetData></worksheet>'];
 $xlsx=$make($entries);$result=$import->parse($xlsx,'a.xlsx');
 $check($result['sheets']===['People','Other']&&$result['sheet']==='People','worksheet discovery and default selection');
 $check($result['rows'][1]===['row'=>7,'cells'=>['ada@example.test','Ada Example']],'shared and inline strings, rich text and source row numbers retained');
 $other=$import->parse($xlsx,'a.xlsx','Other');$check($other['rows'][0]['cells'][0]===''&&str_starts_with($other['rows'][0]['cells'][1],'[Formula:'),'sparse columns preserved and formulas never evaluated');
 $reject(fn()=>$import->parse($xlsx,'a.xlsx','Missing'),'unknown worksheet rejected');
 $evil=$entries;$evil['xl/workbook.xml']='<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]><workbook>&x;</workbook>';$reject(fn()=>$import->parse($make($evil),'a.xlsx'),'XML entities rejected without reading local or remote content');
 $external=$entries;$external['xl/_rels/workbook.xml.rels']='<Relationships><Relationship Id="rId1" TargetMode="External" Target="https://example.test/sheet.xml"/></Relationships>';$reject(fn()=>$import->parse($make($external),'a.xlsx'),'external worksheet links never followed');
 $large=$entries;$large['bomb.txt']=str_repeat('A',21*1024*1024);$reject(fn()=>$import->parse($make($large),'a.xlsx'),'compressed expansion limit checked before XML extraction');
 echo json_encode(['checksPassed'=>$checks,'documentsChanged'=>0,'emailsSent'=>0],JSON_PRETTY_PRINT).PHP_EOL;
}
