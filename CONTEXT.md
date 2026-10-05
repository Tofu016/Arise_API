# Arise API

Backend for the Arise campus app: indoor room navigation, built from photos that admins upload.

## Language

**Photo**:
An image file an admin uploads to be shown in indoor navigation or kiosk signage content.
_Avoid_: Image, upload, file (when you mean the stored thing rather than the act)

**Photo path**:
The relative string that identifies a Photo everywhere, including in the database, e.g. `panoramas/gd1/lobby.webp` or `signage/library.jpg`.
_Avoid_: URL, filename, filepath

**Category**:
The first segment of a Photo path (`panoramas`, `roomphoto`, `room360`, `signage`, …). It decides whether a Photo is public or protected, and how it is laid out on disk.
_Avoid_: Folder, subfolder, type

**Public photo**:
A Photo in a public Category (signage), served directly by Apache to anyone. (Signage may also be a video.)
_Avoid_: Open photo

**Protected photo**:
A Photo in an indoor Category, kept where the web server cannot reach it and streamed only through the serve endpoint (which does not check who is asking: "protected" describes where the file lives, not who may view it).
_Avoid_: Private photo, secure photo

**Photo store**:
Where Photos are saved, found, read and removed, addressed only by Photo path.
_Avoid_: Uploader, file manager, storage service

**Photo column**:
A database column that stores a Photo path. By convention its name contains "photo", or "media" where it may also hold a video (`signage_slides.media_path`).
_Avoid_: Image column, path column

**In-use photo**:
A Photo whose Photo path is stored in at least one Photo column. Only a Photo that is not in use may be deleted.
_Avoid_: Linked photo, active photo

**Orphaned photo**:
A Photo whose Photo path no record references.
_Avoid_: Unused photo, dangling photo
