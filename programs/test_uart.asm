.setcpu "6502"
.org $0200

start:
    LDA #'H'           ; Load 'H'
    STA $FE00          ; Write to UART data register
    LDA #'i'           ; Load 'i'
    STA $FE00          ; Write to UART
    LDA #$0A           ; Newline
    STA $FE00
    JMP $8000          ; Jump to BIOS (or halt)
