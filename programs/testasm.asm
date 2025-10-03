.org $0200

  start:
      LDA #$42        ; Load 0x42 into A
      STA $02FF       ; Store it at $02FF

      ; Send the hex value directly to UART
      LDA $02FF       ; Load the value back
      JSR print_hex   ; Print it as hex

      BRK             ; Return to BIOS

  print_hex:
      PHA             ; Save A
      LSR A
      LSR A  
      LSR A
      LSR A           ; Get high nibble
      JSR print_nibble
      PLA             ; Get original A back
      AND #$0F        ; Get low nibble
      JSR print_nibble
      RTS

  print_nibble:
      CMP #$0A
      BCC digit
      ADC #$36        ; Convert A-F
      JMP send_char

  digit:
      ADC #$30        ; Convert 0-9

  send_char:
      PHA             ; Save character
  wait_ready:
      LDA $FE01       ; Check UART status
      AND #$10        ; Check transmit ready bit
      BEQ wait_ready  ; Wait until ready
      PLA             ; Restore character
      STA $FE00       ; Send to UART
      RTS
