import CryptoKit
import Foundation
import MetricKit
import React

private let diagnosticsDefaults = "rokn_operational_diagnostics"
private let pendingExitEventKey = "pending_exit_event"
private var previousExceptionHandler: (@convention(c) (NSException) -> Void)?

private func sha256(_ value: String) -> String {
  SHA256.hash(data: Data(value.utf8)).map { String(format: "%02x", $0) }.joined()
}

private func persistExitEvent(errorCode: String, fingerprint: String, overwrite: Bool) {
  let defaults = UserDefaults(suiteName: diagnosticsDefaults) ?? .standard
  if !overwrite, defaults.dictionary(forKey: pendingExitEventKey) != nil {
    return
  }

  defaults.set([
    "event_id": UUID().uuidString.lowercased(),
    "error_code": errorCode,
    "error_fingerprint": fingerprint,
    "occurred_at": ISO8601DateFormatter().string(from: Date()),
  ], forKey: pendingExitEventKey)
  // A fatal exception can terminate immediately. Make the small allowlisted
  // diagnostic durable before delegating to the prior crash handler.
  defaults.synchronize()
}

private func roknUncaughtExceptionHandler(_ exception: NSException) {
  let firstFrame = exception.callStackSymbols.first ?? "unknown"
  persistExitEvent(
    errorCode: "NATIVE_CRASH",
    fingerprint: sha256("ios|\(exception.name.rawValue)|\(firstFrame)"),
    overwrite: true
  )
  previousExceptionHandler?(exception)
}

@objc(RoknDiagnostics)
final class RoknDiagnostics: NSObject, MXMetricManagerSubscriber {
  static let shared = RoknDiagnostics()

  @objc static func requiresMainQueueSetup() -> Bool {
    false
  }

  static func install() {
    previousExceptionHandler = NSGetUncaughtExceptionHandler()
    NSSetUncaughtExceptionHandler(roknUncaughtExceptionHandler)
    if #available(iOS 13.0, *) {
      MXMetricManager.shared.add(shared)
    }
  }

  @objc(consumePendingExitEvent:rejecter:)
  func consumePendingExitEvent(
    _ resolve: RCTPromiseResolveBlock,
    rejecter reject: RCTPromiseRejectBlock
  ) {
    let defaults = UserDefaults(suiteName: diagnosticsDefaults) ?? .standard
    resolve(defaults.dictionary(forKey: pendingExitEventKey))
  }

  @objc(acknowledgePendingExitEvent:resolver:rejecter:)
  func acknowledgePendingExitEvent(
    _ eventId: String,
    resolver resolve: RCTPromiseResolveBlock,
    rejecter reject: RCTPromiseRejectBlock
  ) {
    let defaults = UserDefaults(suiteName: diagnosticsDefaults) ?? .standard
    guard let pending = defaults.dictionary(forKey: pendingExitEventKey) else {
      resolve(true)
      return
    }
    guard pending["event_id"] as? String == eventId else {
      resolve(false)
      return
    }
    defaults.removeObject(forKey: pendingExitEventKey)
    resolve(true)
  }

  @available(iOS 14.0, *)
  func didReceive(_ payloads: [MXDiagnosticPayload]) {
    for payload in payloads {
      if !(payload.crashDiagnostics ?? []).isEmpty {
        persistExitEvent(
          errorCode: "NATIVE_CRASH",
          fingerprint: sha256("ios|metrickit|crash"),
          overwrite: false
        )
        return
      }
      if !(payload.hangDiagnostics ?? []).isEmpty {
        persistExitEvent(
          errorCode: "NATIVE_HANG",
          fingerprint: sha256("ios|metrickit|hang"),
          overwrite: false
        )
        return
      }
    }
  }
}
