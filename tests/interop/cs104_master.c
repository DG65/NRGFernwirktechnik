/*
 * Interoperabilitaetstest: lib60870 CS104-Client (Zentralstation) gegen die Unterstation IEC104.
 * Uebersetzen: tests/interop/build.sh. Aufruf: cs104_master <host> <port>
 * Die Bibliothek lib60870 (GPL) wird nur zum Testen benutzt und ist nicht Teil des Moduls.
 */
#include "cs104_connection.h"
#include "hal_time.h"
#include "hal_thread.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <math.h>
#include <time.h>

static int fails = 0, checks = 0;
#define CHECK(cond, ...) do { checks++; if (cond) { printf("  ok:     "); printf(__VA_ARGS__); printf("\n"); } else { fails++; printf("  FEHLER: "); printf(__VA_ARGS__); printf("\n"); } } while (0)

static volatile int startdtCon = 0, stopdtCon = 0, closed = 0;
static volatile int nEI = 0, nGI_SP = 0, nGI_DP = 0, nGI_ME = 0, nActconIC = 0, nActtermIC = 0;
static volatile int nActcon63 = 0, nActcon63neg = 0, nActcon58 = 0, nActterm58 = 0;
static volatile int nSpontME = 0, nRxTestfrAct = 0, nRxTestfrCon = 0;
static volatile double gi_pges = NAN, gi_pv = NAN, sp_back = NAN, spont_val = NAN;
static volatile int gi_dp_val = -1, gi_sp_val = -1;
static volatile int spont_min = -1, spont_sec = -1, spont_su = -1;
static volatile int lastSpontOnMirror = 0;

#define IOA(h, m, l) (((h) << 16) | ((m) << 8) | (l))

static void rawHandler(void* p, uint8_t* msg, int size, bool sent)
{
    if (!sent && size == 6 && msg[2] == 0x43) nRxTestfrAct++;
    if (!sent && size == 6 && msg[2] == 0x83) nRxTestfrCon++;
    if (getenv("RAW")) { printf("%s", sent ? "SEND: " : "RCVD: "); for (int i = 0; i < size; i++) printf("%02x ", msg[i]); printf("\n"); }
}

static void connHandler(void* p, CS104_Connection c, CS104_ConnectionEvent e)
{
    if (e == CS104_CONNECTION_STARTDT_CON_RECEIVED) startdtCon++;
    if (e == CS104_CONNECTION_STOPDT_CON_RECEIVED) stopdtCon++;
    if (e == CS104_CONNECTION_CLOSED) closed++;
}

static bool asduHandler(void* p, int address, CS101_ASDU asdu)
{
    TypeID t = CS101_ASDU_getTypeID(asdu);
    CS101_CauseOfTransmission cot = CS101_ASDU_getCOT(asdu);
    int n = CS101_ASDU_getNumberOfElements(asdu);
    if (t == M_EI_NA_1 && cot == CS101_COT_INITIALIZED) nEI++;
    if (t == C_IC_NA_1) { if (cot == CS101_COT_ACTIVATION_CON) nActconIC++; if (cot == CS101_COT_ACTIVATION_TERMINATION) nActtermIC++; }
    if (t == C_SE_TC_1) {
        if (cot == CS101_COT_ACTIVATION_CON) { nActcon63++; if (CS101_ASDU_isNegative(asdu)) nActcon63neg++; }
    }
    if (t == C_SC_TA_1) {
        if (cot == CS101_COT_ACTIVATION_CON && !CS101_ASDU_isNegative(asdu)) nActcon58++;
        if (cot == CS101_COT_ACTIVATION_TERMINATION) nActterm58++;
    }
    for (int i = 0; i < n; i++) {
        InformationObject io = CS101_ASDU_getElement(asdu, i);
        if (!io) continue;
        int ioa = InformationObject_getObjectAddress(io);
        if (cot == CS101_COT_INTERROGATED_BY_STATION) {
            if (t == M_SP_NA_1) { nGI_SP++; if (ioa == IOA(0,0,11)) gi_sp_val = SinglePointInformation_getValue((SinglePointInformation) io); }
            if (t == M_DP_NA_1) { nGI_DP++; if (ioa == IOA(0,1,125)) gi_dp_val = DoublePointInformation_getValue((DoublePointInformation) io); }
            if (t == M_ME_NC_1) {
                nGI_ME++;
                float v = MeasuredValueShort_getValue((MeasuredValueShort) io);
                if (ioa == IOA(10,0,13)) gi_pges = v;
                if (ioa == IOA(30,0,12)) gi_pv = v;
            }
        }
        if (t == M_ME_TF_1 && cot == CS101_COT_SPONTANEOUS) {
            MeasuredValueShortWithCP56Time2a m = (MeasuredValueShortWithCP56Time2a) io;
            float v = MeasuredValueShort_getValue((MeasuredValueShort) io);
            CP56Time2a ts = MeasuredValueShortWithCP56Time2a_getTimestamp(m);
            if (ioa == IOA(30,0,4)) { sp_back = v; lastSpontOnMirror = 1; }
            if (ioa == IOA(10,0,13)) { spont_val = v; nSpontME++; spont_min = CP56Time2a_getMinute(ts); spont_sec = CP56Time2a_getSecond(ts); spont_su = CP56Time2a_isSummerTime(ts); }
        }
        InformationObject_destroy(io);
    }
    return true;
}

static int waitFor(volatile int* flag, int atLeast, int ms)
{
    for (int i = 0; i < ms / 50; i++) { if (*flag >= atLeast) return 1; Thread_sleep(50); }
    return *flag >= atLeast;
}

int main(int argc, char** argv)
{
    const char* host = argc > 1 ? argv[1] : "127.0.0.1";
    int port = argc > 2 ? atoi(argv[2]) : 2404;
    CS104_Connection con = CS104_Connection_create(host, port);
    struct sCS104_APCIParameters apci = { .k = 12, .w = 8, .t0 = 10, .t1 = 15, .t2 = 10, .t3 = getenv("CLIENT_T3") ? atoi(getenv("CLIENT_T3")) : 30 };
    CS104_Connection_setAPCIParameters(con, &apci);
    CS104_Connection_setRawMessageHandler(con, rawHandler, NULL);
    CS104_Connection_setConnectionHandler(con, connHandler, NULL);
    CS104_Connection_setASDUReceivedHandler(con, asduHandler, NULL);

    printf("1. Verbindung und STARTDT\n");
    CHECK(CS104_Connection_connect(con), "TCP-Verbindung aufgebaut");
    CS104_Connection_sendStartDT(con);
    CHECK(waitFor(&startdtCon, 1, 3000), "STARTDT_CON empfangen");
    CHECK(waitFor(&nEI, 1, 2000), "Initialisierung beendet (M_EI_NA_1, Ursache 4) empfangen");

    printf("2. Generalabfrage (Typen ohne Zeitmarke)\n");
    CHECK(CS104_Connection_sendInterrogationCommand(con, CS101_COT_ACTIVATION, 1, IEC60870_QOI_STATION), "Generalabfrage gesendet");
    CHECK(waitFor(&nActtermIC, 1, 4000), "ACTTERM der Generalabfrage empfangen");
    CHECK(nActconIC == 1, "ACTCON der Generalabfrage empfangen (%d)", nActconIC);
    CHECK(nGI_SP >= 1 && nGI_DP >= 1 && nGI_ME >= 5, "Objekte: %d Einzel (M_SP_NA_1), %d Doppel (M_DP_NA_1), %d Messwerte (M_ME_NC_1)", nGI_SP, nGI_DP, nGI_ME);
    CHECK(fabs(gi_pges - 1.234) < 0.001, "Pgesamt am NAP (10.0.13) = %.3f", gi_pges);
    CHECK(fabs(gi_pv - (-2.0)) < 0.001, "Pges PV (30.0.12) = %.3f (Symcon +2,0 mit Faktor -1)", gi_pv);
    CHECK(gi_sp_val == 1, "Einzelmeldung 0.0.11 = %d", gi_sp_val);
    CHECK(gi_dp_val == 2, "Doppelmeldung 0.1.125 = %d (EIN)", gi_dp_val);

    printf("3. Sollwert (Typ 63) mit Rueckmeldung, ausserhalb des Bereichs\n");
    CP56Time2a now = CP56Time2a_createFromMsTimestamp(NULL, Hal_getTimeInMs());
    InformationObject sp = (InformationObject) SetpointCommandShortWithCP56Time2a_create(NULL, IOA(30,0,1), 60.0f, false, 0, now);
    CHECK(CS104_Connection_sendProcessCommandEx(con, CS101_COT_ACTIVATION, 1, sp), "Sollwert 60 %% gesendet");
    InformationObject_destroy(sp);
    CHECK(waitFor(&nActcon63, 1, 3000), "ACTCON zum Sollwert empfangen");
    CHECK(nActcon63neg == 0, "ACTCON positiv");
    Thread_sleep(300);
    CHECK(fabs(sp_back - 60.0) < 0.001, "Rueckmeldung 30.0.4 spontan = %.3f", sp_back);
    sp = (InformationObject) SetpointCommandShortWithCP56Time2a_create(NULL, IOA(30,0,1), 120.0f, false, 0, now);
    CS104_Connection_sendProcessCommandEx(con, CS101_COT_ACTIVATION, 1, sp);
    InformationObject_destroy(sp);
    CHECK(waitFor(&nActcon63, 2, 3000), "zweite Antwort empfangen");
    CHECK(nActcon63neg == 1, "120 %% (ausserhalb 0..100) mit negativer Quittung abgelehnt");

    printf("4. Einzelbefehl mit Zeitmarke (Typ 58)\n");
    InformationObject sc = (InformationObject) SingleCommandWithCP56Time2a_create(NULL, IOA(1,0,11), true, false, 0, now);
    CS104_Connection_sendProcessCommandEx(con, CS101_COT_ACTIVATION, 1, sc);
    InformationObject_destroy(sc);
    CHECK(waitFor(&nActterm58, 1, 3000), "ACTCON und ACTTERM zum Einzelbefehl empfangen (%d/%d)", nActcon58, nActterm58);
    free(now);

    printf("5. Spontane Messwertaenderung mit Zeitmarke (Server aendert nach ca. 7 s)\n");
    CHECK(waitFor(&nSpontME, 1, 12000), "spontaner Messwert empfangen");
    CHECK(fabs(spont_val - 3.0) < 0.001, "Wert %.3f", spont_val);
    time_t tt = time(NULL); struct tm lt; localtime_r(&tt, &lt);
    int dmin = (lt.tm_min - spont_min + 60) % 60;
    CHECK(dmin <= 1, "Zeitmarke Minute %d (jetzt %d), Sommerzeitbit %d (Ortszeit-Modus)", spont_min, lt.tm_min, spont_su);

    printf("6. Testrahmen bei Ruhe (Client t3 = %d s, Server t3 = 6 s)\n", apci.t3);
    Thread_sleep(9000);
    if (apci.t3 < 6) {
        CHECK(nRxTestfrCon >= 1, "Server hat unseren TESTFR act beantwortet (%d)", nRxTestfrCon);
    } else {
        CHECK(nRxTestfrAct >= 1, "Server hat selbst TESTFR act gesendet (%d); der Client hat automatisch geantwortet", nRxTestfrAct);
    }
    CHECK(CS104_Connection_isConnected(con), "Verbindung steht noch");

    printf("7. STOPDT und erneutes STARTDT\n");
    CS104_Connection_sendStopDT(con);
    CHECK(waitFor(&stopdtCon, 1, 3000), "STOPDT_CON empfangen");
    CS104_Connection_sendStartDT(con);
    CHECK(waitFor(&startdtCon, 2, 3000), "zweites STARTDT_CON empfangen");
    int before = nGI_ME;
    CS104_Connection_sendInterrogationCommand(con, CS101_COT_ACTIVATION, 1, IEC60870_QOI_STATION);
    CHECK(waitFor(&nActtermIC, 2, 4000), "Generalabfrage nach erneutem STARTDT beantwortet");
    CHECK(nGI_ME > before, "erneut Messwerte geliefert");

    CS104_Connection_close(con);
    CS104_Connection_destroy(con);
    printf("\n%d Pruefungen, %d Fehler\n", checks, fails);
    return fails ? 1 : 0;
}
