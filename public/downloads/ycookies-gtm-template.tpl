___INFO___

{
  "type": "MACRO",
  "id": "cvt_temp_public_id",
  "version": 1,
  "securityGroups": [],
  "displayName": "YCookies Consent State",
  "description": "Checks if a specific service has been consented to in YCookies.",
  "containerContexts": [
    "WEB"
  ]
}

___TEMPLATE_PARAMETERS___

[
  {
    "type": "TEXT",
    "name": "serviceKey",
    "displayName": "Service Key",
    "simpleValueType": true,
    "help": "Enter the exact Service Key (e.g., google-analytics) as configured in YCookies.",
    "valueValidators": [
      {
        "type": "NON_EMPTY"
      }
    ]
  }
]

___SANDBOXED_JS_FOR_WEB_TEMPLATE___

const copyFromWindow = require('copyFromWindow');

// The YCookies manager object is attached to window.YCookies.manager
const manager = copyFromWindow('YCookies.manager');

if (manager && manager.hasConsentedService) {
  return manager.hasConsentedService(data.serviceKey) ? true : false;
}

return false;

___WEB_PERMISSIONS___

[
  {
    "instance": {
      "key": {
        "publicId": "access_globals",
        "versionId": "1"
      },
      "param": [
        {
          "key": "keys",
          "value": {
            "type": 2,
            "listItem": [
              {
                "type": 1,
                "string": "YCookies.manager"
              },
              {
                "type": 1,
                "string": "YCookies.manager.hasConsentedService"
              }
            ]
          }
        }
      ]
    },
    "clientAnnotations": {
      "isEditedByUser": true
    },
    "isRequired": true
  }
]

___TESTS___

scenarios: []
