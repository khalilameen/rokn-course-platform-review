#import <React/RCTBridgeModule.h>

@interface RCT_EXTERN_MODULE(RoknDiagnostics, NSObject)

RCT_EXTERN_METHOD(consumePendingExitEvent:(RCTPromiseResolveBlock)resolve
                  rejecter:(RCTPromiseRejectBlock)reject)

RCT_EXTERN_METHOD(acknowledgePendingExitEvent:(NSString *)eventId
                  resolver:(RCTPromiseResolveBlock)resolve
                  rejecter:(RCTPromiseRejectBlock)reject)

@end
