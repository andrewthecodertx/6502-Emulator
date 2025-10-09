; PHP-6502 System BIOS
; Simple BIOS written by Andrew S Erwin
;
; Memory Layout:
; $8000-$8FFF: BIOS code (4KB)
; $FE00-$FE03: UART (W65C51N)
; $FFFC-$FFFD: Reset vector

.setcpu "6502"

; UART Registers (W65C51N ACIA)
UART_DATA    = $FE00
UART_STATUS  = $FE01
UART_COMMAND = $FE02
UART_CONTROL = $FE03

; UART Status bits
UART_RDRF    = $08    ; Receive Data Register Full
UART_TDRE    = $10    ; Transmit Data Register Empty

; Zero page variables
ZP_PTR_LO    = $00    ; String pointer low
ZP_PTR_HI    = $01    ; String pointer high
ZP_TEMP      = $02    ; Temporary storage
; ADDED: Variables for hex address parsing
ZP_HEX_LO    = $03    ; Hex address low byte
ZP_HEX_HI    = $04    ; Hex address high byte
ZP_INPUT_BUF = $0200  ; Input buffer for hex parsing
ZP_INPUT_IDX = $05    ; Input buffer index

.segment "BIOS"
.org $8000

;-----------------------------------------------------------------------------
; RESET - System initialization entry point
;-----------------------------------------------------------------------------
RESET:
    SEI                 ; Disable interrupts
    CLD                 ; Clear decimal mode
    LDX #$FF            ; Initialize stack pointer
    TXS

    ; Clear zero page
    LDA #$00
    LDX #$00
:   STA $00,X
    INX
    BNE :-

    ; Initialize UART
    JSR UART_INIT

    ; ADDED: Initialize input buffer index
    LDA #$00
    STA ZP_INPUT_IDX

    ; Display startup message
    JSR PRINT_BANNER

    ; Run memory test
    JSR MEMORY_TEST

    ; Display ready message
    LDA #<MSG_READY
    STA ZP_PTR_LO
    LDA #>MSG_READY
    STA ZP_PTR_HI
    JSR PRINT_STRING

    ; ADDED: Show initial prompt
    JSR SHOW_PROMPT

    ; System ready - infinite loop waiting for programs
MAIN_LOOP:
    JSR UART_CHECK      ; Check for incoming data
    JMP MAIN_LOOP

;-----------------------------------------------------------------------------
; UART_INIT - Initialize W65C51N UART
;-----------------------------------------------------------------------------
UART_INIT:
    ; Reset UART
    LDA #$1F            ; Reset command
    STA UART_COMMAND

    ; Configure UART: 19200 baud, 8N1
    LDA #$0B            ; No parity, 1 stop bit, 8 data bits
    STA UART_CONTROL

    ; Enable TX/RX, DTR active
    LDA #$19            ; Enable transmitter/receiver, DTR active
    STA UART_COMMAND

    RTS

;-----------------------------------------------------------------------------
; UART_PUTCHAR - Send character in A to UART
; Input: A = character to send
; Preserves: X, Y
;-----------------------------------------------------------------------------
UART_PUTCHAR:
    PHA                 ; Save character
@wait:
    LDA UART_STATUS     ; Check status
    AND #UART_TDRE      ; Transmit Data Register Empty?
    BEQ @wait           ; Wait until ready

    PLA                 ; Restore character
    STA UART_DATA       ; Send it
    RTS

;-----------------------------------------------------------------------------
; UART_GETCHAR - Get character from UART
; Output: A = character (0 if none available), C = 1 if char received
;-----------------------------------------------------------------------------
UART_GETCHAR:
    LDA UART_STATUS     ; Check status
    AND #UART_RDRF      ; Receive Data Register Full?
    BEQ @no_data        ; No data available

    LDA UART_DATA       ; Get character
    SEC                 ; Set carry = data available
    RTS

@no_data:
    LDA #$00            ; No character
    CLC                 ; Clear carry = no data
    RTS

;-----------------------------------------------------------------------------
; UART_CHECK - Check for and echo any received characters
; ADDED: Now supports hex address parsing for direct jumps
;-----------------------------------------------------------------------------
UART_CHECK:
    JSR UART_GETCHAR    ; Check for input
    BCC @done           ; No data, done

    ; Check for special commands first
    CMP #$03            ; Ctrl+C?
    BEQ @ctrl_c
    CMP #$0D            ; Carriage return?
    BEQ @process_input
    CMP #$08            ; Backspace?
    BEQ @backspace
    CMP #$7F            ; DEL?
    BEQ @backspace

    ; Check if it's a hex character (0-9, A-F, a-f)
    JSR IS_HEX_CHAR
    BCC @invalid_char   ; Not hex, ignore

    ; Add to input buffer if there's room
    LDX ZP_INPUT_IDX
    CPX #$04            ; Max 4 hex digits
    BCS @done           ; Buffer full, ignore

    ; Echo character and add to buffer
    JSR UART_PUTCHAR
    STA ZP_INPUT_BUF,X
    INC ZP_INPUT_IDX

@done:
    RTS

@invalid_char:
    ; Don't echo invalid characters, just ignore
    RTS

@backspace:
    ; Handle backspace - remove last character
    LDX ZP_INPUT_IDX
    BEQ @done           ; Nothing to delete
    DEX
    STX ZP_INPUT_IDX
    ; Send backspace sequence: BS, space, BS
    LDA #$08
    JSR UART_PUTCHAR
    LDA #$20
    JSR UART_PUTCHAR
    LDA #$08
    JSR UART_PUTCHAR
    RTS

@process_input:
    ; Send newline
    LDA #$0A
    JSR UART_PUTCHAR

    ; Check if we have any input
    LDX ZP_INPUT_IDX
    BEQ @show_prompt    ; No input, just show prompt again

    ; Parse hex address and jump
    JSR PARSE_HEX_ADDRESS
    BCC @invalid_address ; Invalid hex

    ; Show jump message
    JSR PRINT_JUMP_MSG

    ; Clear input buffer and jump
    LDX #$00
    STX ZP_INPUT_IDX
    JMP (ZP_HEX_LO)     ; Jump to parsed address

@invalid_address:
    ; Show error and reset
    JSR PRINT_ERROR_MSG
    LDX #$00
    STX ZP_INPUT_IDX
    JSR SHOW_PROMPT
    RTS

@show_prompt:
    JSR SHOW_PROMPT
    RTS

@ctrl_c:
    JSR PRINT_INFO      ; Show system info
    JSR SHOW_PROMPT
    RTS

;-----------------------------------------------------------------------------
; PRINT_STRING - Print null-terminated string
; Input: ZP_PTR_LO/HI = pointer to string
;-----------------------------------------------------------------------------
PRINT_STRING:
    LDY #$00
@loop:
    LDA (ZP_PTR_LO),Y   ; Get character
    BEQ @done           ; Null terminator?
    JSR UART_PUTCHAR    ; Send character
    INY                 ; Next character
    BNE @loop           ; Continue (assumes string < 256 chars)
@done:
    RTS

;-----------------------------------------------------------------------------
; PRINT_HEX_BYTE - Print byte in A as hex
;-----------------------------------------------------------------------------
PRINT_HEX_BYTE:
    PHA                 ; Save byte
    LSR A
    LSR A
    LSR A
    LSR A               ; Get high nibble
    JSR @print_nibble

    PLA                 ; Restore byte
    AND #$0F            ; Get low nibble
@print_nibble:
    CMP #$0A
    BCC @digit
    ADC #$36            ; Convert A-F (with carry set)
    JMP UART_PUTCHAR
@digit:
    ADC #$30            ; Convert 0-9
    JMP UART_PUTCHAR

;-----------------------------------------------------------------------------
; PRINT_HEX_WORD - Print word in X(low)/Y(high) as hex
;-----------------------------------------------------------------------------
PRINT_HEX_WORD:
    TYA                 ; High byte
    JSR PRINT_HEX_BYTE
    TXA                 ; Low byte
    JMP PRINT_HEX_BYTE

;-----------------------------------------------------------------------------
; MEMORY_TEST - Basic RAM test
;-----------------------------------------------------------------------------
MEMORY_TEST:
    ; Set string pointer to test message
    LDA #<MSG_MEMTEST
    STA ZP_PTR_LO
    LDA #>MSG_MEMTEST
    STA ZP_PTR_HI
    JSR PRINT_STRING

    ; Test zero page (except our variables)
    LDX #$10            ; Start at $10
@zp_loop:
    TXA                 ; Test pattern = address
    STA $00,X           ; Write to zero page
    CMP $00,X           ; Read back and compare
    BNE @zp_fail
    INX
    BNE @zp_loop

    ; Test stack page
    LDX #$00
@stack_loop:
    TXA                 ; Test pattern = address
    STA $0100,X         ; Write to stack page
    CMP $0100,X         ; Read back and compare
    BNE @stack_fail
    INX
    BNE @stack_loop

    ; Memory test passed
    LDA #<MSG_MEMOK
    STA ZP_PTR_LO
    LDA #>MSG_MEMOK
    STA ZP_PTR_HI
    JSR PRINT_STRING
    RTS

@zp_fail:
    LDA #<MSG_ZPFAIL
    STA ZP_PTR_LO
    LDA #>MSG_ZPFAIL
    STA ZP_PTR_HI
    JSR PRINT_STRING
    RTS

@stack_fail:
    LDA #<MSG_STACKFAIL
    STA ZP_PTR_LO
    LDA #>MSG_STACKFAIL
    STA ZP_PTR_HI
    JSR PRINT_STRING
    RTS

;-----------------------------------------------------------------------------
; PRINT_BANNER - Display startup banner
;-----------------------------------------------------------------------------
PRINT_BANNER:
    LDA #<MSG_BANNER
    STA ZP_PTR_LO
    LDA #>MSG_BANNER
    STA ZP_PTR_HI
    JSR PRINT_STRING

    RTS

;-----------------------------------------------------------------------------
; PRINT_INFO - Display system information
;-----------------------------------------------------------------------------
PRINT_INFO:
    LDA #<MSG_SYSINFO
    STA ZP_PTR_LO
    LDA #>MSG_SYSINFO
    STA ZP_PTR_HI
    JSR PRINT_STRING

    ; Show current stack pointer
    LDA #<MSG_SP
    STA ZP_PTR_LO
    LDA #>MSG_SP
    STA ZP_PTR_HI
    JSR PRINT_STRING

    TSX                 ; Get stack pointer
    TXA
    JSR PRINT_HEX_BYTE

    LDA #$0D            ; Carriage return
    JSR UART_PUTCHAR
    LDA #$0A            ; Line feed
    JSR UART_PUTCHAR

    RTS

;-----------------------------------------------------------------------------
; PRINT_WOZMON_MSG - Display WozMon launch message
;-----------------------------------------------------------------------------
PRINT_WOZMON_MSG:
    LDA #<MSG_WOZMON
    STA ZP_PTR_LO
    LDA #>MSG_WOZMON
    STA ZP_PTR_HI
    JSR PRINT_STRING
    RTS

;-----------------------------------------------------------------------------
; ADDED: IS_HEX_CHAR - Check if character in A is valid hex (0-9, A-F, a-f)
; Input: A = character to check
; Output: C = 1 if hex, 0 if not hex. A = uppercase hex char if valid
;-----------------------------------------------------------------------------
IS_HEX_CHAR:
    ; Check for 0-9
    CMP #'0'
    BCC @not_hex
    CMP #'9'+1
    BCC @is_hex

    ; Convert lowercase to uppercase
    CMP #'a'
    BCC @check_upper
    CMP #'f'+1
    BCS @not_hex
    SEC
    SBC #$20            ; Convert to uppercase

@check_upper:
    ; Check for A-F
    CMP #'A'
    BCC @not_hex
    CMP #'F'+1
    BCS @not_hex

@is_hex:
    SEC                 ; Set carry = valid hex
    RTS

@not_hex:
    CLC                 ; Clear carry = not hex
    RTS

;-----------------------------------------------------------------------------
; ADDED: PARSE_HEX_ADDRESS - Parse hex string in input buffer to address
; Output: C = 1 if valid, ZP_HEX_LO/HI = parsed address
;-----------------------------------------------------------------------------
PARSE_HEX_ADDRESS:
    ; Clear result
    LDA #$00
    STA ZP_HEX_LO
    STA ZP_HEX_HI

    ; Check if we have input
    LDX ZP_INPUT_IDX
    BEQ @invalid

    ; Process each hex digit
    LDY #$00
@parse_loop:
    CPY ZP_INPUT_IDX
    BEQ @valid          ; Processed all digits

    ; Get character
    LDA ZP_INPUT_BUF,Y

    ; Convert hex char to value
    CMP #'9'+1
    BCC @digit
    SEC
    SBC #$07            ; A-F becomes $0A-$0F
@digit:
    SEC
    SBC #'0'            ; Convert to binary value

    ; Shift result left 4 bits and add new digit
    ; Shift ZP_HEX_HI:ZP_HEX_LO left 4 positions
    LDX #$04
@shift_loop:
    ASL ZP_HEX_LO
    ROL ZP_HEX_HI
    DEX
    BNE @shift_loop

    ; Add new digit to low byte
    ORA ZP_HEX_LO
    STA ZP_HEX_LO

    INY
    JMP @parse_loop

@valid:
    SEC                 ; Valid address parsed
    RTS

@invalid:
    CLC                 ; Invalid
    RTS

;-----------------------------------------------------------------------------
; ADDED: SHOW_PROMPT - Display BIOS prompt
;-----------------------------------------------------------------------------
SHOW_PROMPT:
    LDA #<MSG_PROMPT
    STA ZP_PTR_LO
    LDA #>MSG_PROMPT
    STA ZP_PTR_HI
    JSR PRINT_STRING
    RTS

;-----------------------------------------------------------------------------
; ADDED: PRINT_JUMP_MSG - Show jumping message
;-----------------------------------------------------------------------------
PRINT_JUMP_MSG:
    LDA #<MSG_JUMPING
    STA ZP_PTR_LO
    LDA #>MSG_JUMPING
    STA ZP_PTR_HI
    JSR PRINT_STRING

    ; Print the address we're jumping to
    LDA ZP_HEX_HI
    JSR PRINT_HEX_BYTE
    LDA ZP_HEX_LO
    JSR PRINT_HEX_BYTE

    LDA #$0D
    JSR UART_PUTCHAR
    LDA #$0A
    JSR UART_PUTCHAR
    RTS

;-----------------------------------------------------------------------------
; ADDED: PRINT_ERROR_MSG - Show error message
;-----------------------------------------------------------------------------
PRINT_ERROR_MSG:
    LDA #<MSG_ERROR
    STA ZP_PTR_LO
    LDA #>MSG_ERROR
    STA ZP_PTR_HI
    JSR PRINT_STRING
    RTS

;-----------------------------------------------------------------------------
; WOZ Monitor - Embedded in BIOS ROM
; Based on Steve Wozniak's original monitor adapted for UART
;-----------------------------------------------------------------------------

; WozMon variables in zero page (same as original)
WOZMON_XAML     = $24           ; Last "opened" location Low
WOZMON_XAMH     = $25           ; Last "opened" location High
WOZMON_STL      = $26           ; Store address Low
WOZMON_STH      = $27           ; Store address High
WOZMON_L        = $28           ; Hex value parsing Low
WOZMON_H        = $29           ; Hex value parsing High
WOZMON_YSAV     = $2A           ; Used to see if hex value is given
WOZMON_MODE     = $2B           ; $00=XAM, $7F=STOR, $AE=BLOCK XAM

; Input buffer
WOZMON_IN       = $0280         ; Input buffer at $0280-$02FF

WOZMON_START:
                CLD             ; Clear decimal arithmetic mode
                CLI

WOZMON_NOTCR:   CMP #'_'        ; "_" (backspace)?
                BEQ WOZMON_BACKSPACE
                CMP #$1B        ; ESC?
                BEQ WOZMON_ESCAPE
                INY             ; Advance text index
                BPL WOZMON_NEXTCHAR

WOZMON_ESCAPE:  LDA #'\'        ; "\" (prompt character)
                JSR UART_PUTCHAR

WOZMON_GETLINE: LDA #$0D        ; CR
                JSR UART_PUTCHAR
                LDY #$01        ; Initialize text index

WOZMON_BACKSPACE:
                DEY             ; Back up text index
                BMI WOZMON_GETLINE

WOZMON_NEXTCHAR:
                LDA UART_STATUS ; Check UART status
                AND #UART_RDRF  ; Receiver ready?
                BEQ WOZMON_NEXTCHAR
                LDA UART_DATA   ; Load character
                STA WOZMON_IN,Y ; Add to text buffer
                JSR UART_PUTCHAR
                CMP #$0D        ; CR?
                BNE WOZMON_NOTCR

                LDY #$FF        ; Reset text index
                LDA #$00        ; For XAM mode
                TAX             ; 0->X

WOZMON_SETSTOR: ASL             ; Leaves $7B if setting STOR mode
WOZMON_SETMODE: STA WOZMON_MODE ; $00=XAM, $7B=STOR, $AE=BLOCK XAM

WOZMON_BLSKIP:  INY             ; Advance text index
WOZMON_NEXTITEM:
                LDA WOZMON_IN,Y ; Get character
                CMP #$0D        ; CR?
                BEQ WOZMON_GETLINE
                CMP #'.'        ; "."?
                BCC WOZMON_BLSKIP
                BEQ WOZMON_SETMODE
                CMP #':'        ; ":"?
                BEQ WOZMON_SETSTOR
                CMP #'R'        ; "R"?
                BEQ WOZMON_RUN
                CMP #'B'        ; "B" for BIOS return?
                BNE @not_bios   ; Not B, continue
                JMP MAIN_LOOP   ; Return to BIOS main loop
@not_bios:
                STX WOZMON_L    ; $00->L
                STX WOZMON_H    ; and H
                STY WOZMON_YSAV ; Save Y for comparison

WOZMON_NEXTHEX: LDA WOZMON_IN,Y ; Get character for hex test
                EOR #$30        ; Map digits to $0-9
                CMP #$0A        ; Digit?
                BCC WOZMON_DIG
                ADC #$88        ; Map letter "A"-"F" to $FA-FF
                CMP #$FA        ; Hex letter?
                BCS WOZMON_DIG  ; Yes, continue as hex digit
                JMP WOZMON_NOTHEX ; No, not hex

WOZMON_DIG:     ASL
                ASL             ; Hex digit to MSD of A
                ASL
                ASL
                LDX #$04        ; Shift count

WOZMON_HEXSHIFT:
                ASL             ; Hex digit left, MSB to carry
                ROL WOZMON_L    ; Rotate into LSD
                ROL WOZMON_H    ; Rotate into MSD's
                DEX             ; Done 4 shifts?
                BNE WOZMON_HEXSHIFT
                INY             ; Advance text index
                BNE WOZMON_NEXTHEX

WOZMON_NOTHEX:  CPY WOZMON_YSAV ; Check if L, H empty (no hex digits)
                BNE WOZMON_CONTINUE ; Not empty, continue
                JMP WOZMON_ESCAPE   ; Empty, escape
WOZMON_CONTINUE:
                BIT WOZMON_MODE ; Test MODE byte
                BVC WOZMON_NOTSTOR
                LDA WOZMON_L    ; LSD's of hex data
                STA (WOZMON_STL,X)
                INC WOZMON_STL  ; Increment store index
                BNE WOZMON_TONEXTITEM
                INC WOZMON_STH  ; Add carry to 'store index' high order

WOZMON_TONEXTITEM:
                JMP WOZMON_NEXTITEM
WOZMON_RUN:     JMP (WOZMON_XAML) ; Run at current XAM index

WOZMON_NOTSTOR: BMI WOZMON_XAMNEXT
                LDX #$02        ; Byte count

WOZMON_SETADR:  LDA WOZMON_L-1,X ; Copy hex data to
                STA WOZMON_STL-1,X ; 'store index'
                STA WOZMON_XAML-1,X ; And to 'XAM index'
                DEX             ; Next of 2 bytes
                BNE WOZMON_SETADR

WOZMON_NXTPRNT: BNE WOZMON_PRDATA
                LDA #$0D        ; CR
                JSR UART_PUTCHAR
                LDA WOZMON_XAMH ; 'Examine index' high-order byte
                JSR WOZMON_PRBYTE
                LDA WOZMON_XAML ; Low-order 'examine index' byte
                JSR WOZMON_PRBYTE
                LDA #':'        ; ":"
                JSR UART_PUTCHAR

WOZMON_PRDATA:  LDA #$20        ; Space
                JSR UART_PUTCHAR
                LDA (WOZMON_XAML,X)
                JSR WOZMON_PRBYTE

WOZMON_XAMNEXT: STX WOZMON_MODE ; 0->MODE (XAM mode)
                LDA WOZMON_XAML
                CMP WOZMON_L    ; Compare 'examine index' to hex data
                LDA WOZMON_XAMH
                SBC WOZMON_H
                BCS WOZMON_TONEXTITEM
                INC WOZMON_XAML
                BNE WOZMON_MOD8CHK
                INC WOZMON_XAMH

WOZMON_MOD8CHK: LDA WOZMON_XAML ; Check low-order 'examine index' byte
                AND #$07        ; For MOD 8=0
                BPL WOZMON_NXTPRNT

WOZMON_PRBYTE:  PHA             ; Save A for LSD
                LSR
                LSR
                LSR             ; MSD to LSD position
                LSR
                JSR WOZMON_PRHEX
                PLA             ; Restore A

WOZMON_PRHEX:   AND #$0F        ; Mask LSD for hex print
                ORA #'0'        ; Add "0"
                CMP #$3A        ; Digit?
                BCS @letter     ; No, it's a letter
                JMP UART_PUTCHAR ; Yes, output it
@letter:
                ADC #$06        ; Add offset for letter
                JMP UART_PUTCHAR

;-----------------------------------------------------------------------------
; NMI Handler
;-----------------------------------------------------------------------------
NMI_HANDLER:
    RTI                 ; Simple return for now

;-----------------------------------------------------------------------------
; IRQ Handler
;-----------------------------------------------------------------------------
IRQ_HANDLER:
    RTI                 ; Simple return for now

;-----------------------------------------------------------------------------
; String data
;-----------------------------------------------------------------------------
MSG_BANNER:
    .byte $0D, $0A
    .asciiz "PHP-6502 BIOS v1.0 written by Andrew S Erwin"

MSG_MEMTEST:
    .byte $0D, $0A
    .asciiz "Testing RAM... "

MSG_MEMOK:
    .asciiz "OK"

MSG_ZPFAIL:
    .asciiz "Zero Page FAIL"

MSG_STACKFAIL:
    .asciiz "Stack FAIL"

MSG_SYSINFO:
    .byte $0D, $0A
    .asciiz "System Info - SP: $"

MSG_SP:
    .asciiz "SP: $"

MSG_READY:
    .byte $0D, $0A
    .asciiz "System Ready. Type hex address (e.g. 7E00) to jump, Ctrl+C for info."
    .byte $0D, $0A, $00

; ADDED: New messages for hex address jumping
MSG_PROMPT:
    .asciiz "BIOS> "

MSG_JUMPING:
    .asciiz "Jumping to $"

MSG_ERROR:
    .byte $0D, $0A
    .asciiz "Invalid hex address. Use 1-4 hex digits (e.g. 7E00)."
    .byte $0D, $0A, $00

;-----------------------------------------------------------------------------
; Interrupt Vectors
;-----------------------------------------------------------------------------
.segment "VECTORS"
.org $FFFA
    .word NMI_HANDLER   ; NMI vector
    .word RESET         ; Reset vector
    .word IRQ_HANDLER   ; IRQ vector
