; Tauri NSIS hook: always create the app shortcuts for interactive and silent installs.
; startMenuFolder in tauri.conf.json is "WP Store Manager".
!macro NSIS_HOOK_POSTINSTALL
  SetOutPath "$INSTDIR"
  CreateDirectory "$SMPROGRAMS\WP Store Manager"
  CreateShortcut "$SMPROGRAMS\WP Store Manager\WP Store Manager Desktop.lnk" "$INSTDIR\${MAINBINARYNAME}.exe" "" "$INSTDIR\${MAINBINARYNAME}.exe" 0
  CreateShortcut "$DESKTOP\WP Store Manager Desktop.lnk" "$INSTDIR\${MAINBINARYNAME}.exe" "" "$INSTDIR\${MAINBINARYNAME}.exe" 0
!macroend

; Remove the app shortcuts we own before the application files are removed.
!macro NSIS_HOOK_PREUNINSTALL
  Delete "$SMPROGRAMS\WP Store Manager\WP Store Manager Desktop.lnk"
  Delete "$DESKTOP\WP Store Manager Desktop.lnk"
!macroend

!macro NSIS_HOOK_POSTUNINSTALL
  RMDir "$SMPROGRAMS\WP Store Manager"
!macroend
