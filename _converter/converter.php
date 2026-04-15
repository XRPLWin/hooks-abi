<?php
require 'vendor/autoload.php';

/**
 * Converter from old schema to new schema
 */
class Converter
{
  private readonly string $hookHash;
  private readonly array $def;
  private string $name = '';      //root.name
  private array $hookStates = []; //root.abi.hookStates

  public function __construct($hookHash,$hookstatesDefinition) {
    $this->hookHash = $hookHash;
    $this->def = $hookstatesDefinition;



    $this->convert();
  }

  private function convert() {
    // Conversion logic

    $this->name = $this->def['name'] ?? '';
    $name2 = $this->def['hook_definition']['name'] ?? null;
    if($name2 !== null) {
      $this->name = $name2;
    }

    $projections = $this->def['hook_definition']['hook_states']['fields'] ?? [];

    $final = [];
    foreach($projections as $projection) {
      $r = [
        'name' => $projection['name'] ?? 'Unknown',
        'description' => '',
        'selector' => [],
        'data' => [],
      ];
      
      //hookstate_key -> selector
      foreach($projection['hookstate_key'] ?? [] as $hkey) {
        $r['selector'][] = $this->oldSegmentToNewSegment($hkey);
      }
      //hookstate_data -> data
      foreach($projection['hookstate_data'] ?? [] as $hdata) {
        $r['data'][] = $this->oldSegmentToNewSegment($hdata);
      }

      $final[] = $r;
    }


    $full = [
      'name' => $this->name,
      'description' => null,
      'icon' => null,
      'license' => 'Unlicensed',
      'publishers' => [],
      'readme' => null,
      'repo' => null,
      'abi' => [
        'hookStates' => $final,
      ],
    ];

    //store $full in the directory ../v2/$hookHash/manifest.json
    $dir = __DIR__ . '/../v2/' . $this->hookHash;
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($dir . '/manifest.json', json_encode($full, JSON_PRETTY_PRINT));

    //dd($full);
  }

  private function oldSegmentToNewSegment($oldSegment) {

    $type = $oldSegment['type'] ?? null;
    if($type === null)
      throw new Exception("Missing type in old segment: " . json_encode($oldSegment));

    $type = $this->oldTypeNameToNewTypeName($type);

    $r = $this->processTypeAndLabelFields($type, $oldSegment);
    $r = $this->processByteLengthField($r, $type, $oldSegment);
    $r = $this->processPatternField($r, $type, $oldSegment);

    /*if($type == 'AccountID') {
      return [
        'type' => 'AccountID',
        'label' => $oldSegment['account_id'] ?? null,
      ];
    }*/

    //throw new \Exception('Unhandled type '.$type);
    return $r;
  }

  private function processPatternField($r, $type, $oldSegment) {
    $isBinaryFlag = isset($oldSegment['binary']) && $oldSegment['binary'] === true;
    if(isset($oldSegment['pattern'])) {
      $r['pattern'] = $oldSegment['pattern'];
      $r['patternEncoding'] = $isBinaryFlag ? 'hex' : 'ascii';


      if($type == 'VarString') {
        $isAllZeros = preg_match('/^0+$/', $r['pattern']) === 1;
        if($isAllZeros) {
          $r['type'] = 'Null';
          unset($r['pattern']);
          unset($r['patternEncoding']);
          //add exclude = true
          $r['exclude'] = true;
        }
      }
      




    }

 
    return $r;
  }

  private function processByteLengthField($r, $type, $oldSegment) {

  
    if($this->hasKnownByteLength($type)) {
      //if the type has a known byte length, we can skip it
      return $r;
    }


    if(isset($oldSegment['byte_length'])) {
      $r['byteLength'] = (int)$oldSegment['byte_length'];
    }
    return $r;
  }

  private function processTypeAndLabelFields($type, $oldSegment) {
    $label = $oldSegment['name'] ?? $type; //fallback label is the type itself
    if(empty($label)) {
      $label = $type; //if label is empty string, use type as label
    }
    return [
      'type' => $type,
      'label' => $label,
    ];
  }

  private function hasKnownByteLength($type) {
    $has = [
      'AccountID',
    ];
    return in_array($type, $has);
  }

  private function oldTypeNameToNewTypeName($oldTypeName) {
    $mapping = [
      'UInt8' => 'UInt8',
      'UInt16' => 'UInt16',
      'UInt32' => 'UInt32',
      'UInt64' => 'UInt64',
      'AccountID' => 'AccountID',
      'VarString' => 'VarString',
      'Hash128' => 'Hash128',
      'Hash160' => 'Hash160',
      'Hash256' => 'Hash256',
      'Hash512' => 'Hash512',
      'Null' => 'Null',
      'XFL' => 'XFL',
      'Array' => 'Blob', //array not supported
      'UndeterminedVarString' => 'VarString', //treat undetermined varstring as varstring
      
    ];
    if(!isset($mapping[$oldTypeName])) {
      throw new Exception("Unknown type name: " . $oldTypeName);
    }

    return $mapping[$oldTypeName];
  }
}

//loop all local directories that have 64 character names
$dir = new DirectoryIterator(__DIR__.'/..');
foreach ($dir as $fileinfo) {
    if ($fileinfo->isDir() && !$fileinfo->isDot() && strlen($fileinfo->getFilename()) == 64) {
        echo "Processing directory: " . $fileinfo->getFilename() . "\n";
        
        $hookstatesDefinitionJsonPath = $fileinfo->getPathname() . '/hookstates-definition.json';
        if (file_exists($hookstatesDefinitionJsonPath)) {
          $contents = file_get_contents($hookstatesDefinitionJsonPath);
          $hookstatesDefinition = json_decode($contents, true);
          $converter = new Converter($fileinfo->getFilename(), $hookstatesDefinition);
        } else {
          throw new Exception("File not found: " . $hookstatesDefinitionJsonPath);
        }
    }
}