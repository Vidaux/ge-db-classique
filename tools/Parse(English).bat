@echo off
pushd "%~dp0"
if not exist "..\ge\xml\datatable_job.xml" (
	echo Missing extracted XML in ..\ge\xml.
	echo Run Prepare.bat first, then run Parse^(English^).bat again.
	popd
	exit /b 1
)
php\php.exe English.php --all
popd
