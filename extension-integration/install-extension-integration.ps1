$ErrorActionPreference = 'Stop'
$sourceRoot = Split-Path -Parent $PSScriptRoot
$extensionRoot = Join-Path (Split-Path -Parent $sourceRoot) 'google-maps-extractor-main'

Copy-Item (Join-Path $PSScriptRoot 'leadspace-bg.js') (Join-Path $extensionRoot 'js\leadspace-bg.js') -Force
Copy-Item (Join-Path $PSScriptRoot 'popup.html') (Join-Path $extensionRoot 'popup.html') -Force
Copy-Item (Join-Path $PSScriptRoot 'popup.js') (Join-Path $extensionRoot 'js\popup.js') -Force

$manifestPath = Join-Path $extensionRoot 'manifest.json'
$manifest = Get-Content $manifestPath -Raw | ConvertFrom-Json
$manifest.permissions = @($manifest.permissions + 'identity' | Select-Object -Unique)
$manifest.version = '3.0.0'
$manifest.name = 'Leadspace Maps Extractor'
$manifest.description = 'Save Google Maps business leads directly to an authorized Leadspace workspace and Lead List.'
$manifestJson = $manifest | ConvertTo-Json -Depth 20
[IO.File]::WriteAllText($manifestPath, $manifestJson, (New-Object Text.UTF8Encoding($false)))

$backgroundPath = Join-Path $extensionRoot 'bg.js'
$background = Get-Content $backgroundPath -Raw
if (-not $background.Contains('js/leadspace-bg.js')) {
    $background = $background.TrimEnd() + "`r`nimportScripts(`"js/leadspace-bg.js`");`r`n"
    Set-Content $backgroundPath $background -Encoding utf8
}

$contentPath = Join-Path $extensionRoot 'contentScript.js'
$content = Get-Content $contentPath -Raw
$startOld = 'else{extractionLimit=Math.max(0,parseInt(limit.value,10)||0);runLeadKeys=new Set;runDetailedKeys=new Set;extractionRunning=true;d.innerText="Stop Auto Extract";'
$startNew = 'else{const access=await chrome.runtime.sendMessage({action:"leadspaceCanExtract"});if(!access?.ok){alert(access?.error||"Connect Leadspace before extracting.");return}extractionLimit=Math.max(0,parseInt(limit.value,10)||0);runLeadKeys=new Set;runDetailedKeys=new Set;extractionRunning=true;d.innerText="Stop Auto Extract";'
if ($content.Contains($startOld)) { $content = $content.Replace($startOld, $startNew) }
elseif (-not $content.Contains('action:"leadspaceCanExtract"')) { throw 'Could not find the extraction start handler.' }

$buttonOld = 'a.appendChild(e);a.appendChild(hint);a.appendChild(limit);a.appendChild(f);a.appendChild(g);a.appendChild(k);'
$buttonNew = 'const save=document.createElement("button");save.className="extension_gms_button";save.innerText="Save to Leadspace";save.id="extension_gms_save_btn";save.style="background-color:#2468e8";save.addEventListener("click",async()=>{const current=runLeadKeys.size?leads.filter(lead=>runLeadKeys.has(getLeadKey(lead))):leads;if(!current.length){alert("Extract leads before saving.");return}save.disabled=true;save.innerText="Saving...";try{const result=await chrome.runtime.sendMessage({action:"leadspaceSave",data:current});if(!result?.ok)throw new Error(result?.error||"Could not save leads.");alert(`${result.data.saved} leads saved to ${result.data.list}.`)}catch(error){alert(error.message)}finally{save.disabled=false;save.innerText="Save to Leadspace"}});a.appendChild(e);a.appendChild(hint);a.appendChild(limit);a.appendChild(f);a.appendChild(save);a.appendChild(g);a.appendChild(k);'
if ($content.Contains($buttonOld)) { $content = $content.Replace($buttonOld, $buttonNew) }
elseif (-not $content.Contains('extension_gms_save_btn')) { throw 'Could not find the Maps toolbar buttons.' }
Set-Content $contentPath $content -Encoding utf8

Write-Output "Leadspace integration installed in $extensionRoot"
