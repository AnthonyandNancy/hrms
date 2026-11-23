$ErrorActionPreference = 'Stop'
$word = New-Object -ComObject Word.Application
$word.Visible = $false
$word.DisplayAlerts = 0
$files = @(
  @{ In = "D:\code\ai\factoryHR\HRMS_V1.0_解决方案_第一性原理重构.docx"; Out = "D:\code\ai\factoryHR\交付物\HRMS_V1.0_解决方案_第一性原理重构.pdf" },
  @{ In = "D:\code\ai\factoryHR\HRMS_V1.0_实施计划_30周路线图.docx"; Out = "D:\code\ai\factoryHR\交付物\HRMS_V1.0_实施计划_30周路线图.pdf" }
)
foreach ($f in $files) {
  $doc = $word.Documents.Open($f.In, $false, $true)
  $doc.SaveAs([ref]$f.Out, [ref]17)
  $doc.Close($false)
}
$word.Quit()
